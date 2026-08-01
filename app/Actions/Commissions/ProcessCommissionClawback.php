<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionStatus;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Jobs\ProcessCommissionClawbackJob;
use App\Models\Commission;
use App\Models\CommissionClawback;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\CommissionClawbackNeedsReviewNotification;
use App\Notifications\CommissionReversalPostedNotification;
use App\Services\NotificationRecipientService;
use App\Services\SystemEventService;
use App\Services\WalletLedger;
use App\Support\Commissions\CommissionClawbackCalculator;
use App\Support\Commissions\CommissionClawbackPolicy;
use App\Support\CustomerFinancialBroadcaster;
use App\Support\Financial\ReceiptSnapshot;
use App\Support\LedgerMoney;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Posts an authorised commission_reversal for a durable clawback obligation.
 *
 * Lock order: clawback → commission → original credit TX → salesperson wallet (via WalletLedger).
 */
final class ProcessCommissionClawback
{
    public function __construct(
        private readonly WalletLedger $ledger = new WalletLedger,
        private readonly CommissionClawbackCalculator $calculator = new CommissionClawbackCalculator,
    ) {}

    public function handle(int $clawbackId): CommissionClawback
    {
        $result = DB::transaction(function () use ($clawbackId): array {
            /** @var CommissionClawback $clawback */
            $clawback = CommissionClawback::query()
                ->whereKey($clawbackId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($clawback->status === CommissionClawbackStatus::Posted) {
                return ['clawback' => $clawback, 'notify_posted' => false, 'notify_review' => false, 'replay' => true];
            }

            if ($clawback->status === CommissionClawbackStatus::Waived) {
                return ['clawback' => $clawback, 'notify_posted' => false, 'notify_review' => false, 'replay' => true];
            }

            if ((new \App\Support\Commissions\CommissionClawbackDisputeState)->hasActiveDispute($clawback)) {
                return ['clawback' => $clawback, 'notify_posted' => false, 'notify_review' => false, 'replay' => true];
            }

            if ($clawback->status === CommissionClawbackStatus::NeedsReview) {
                return ['clawback' => $clawback, 'notify_posted' => false, 'notify_review' => false, 'replay' => true];
            }

            if (! in_array($clawback->status, [
                CommissionClawbackStatus::Pending,
                CommissionClawbackStatus::Processing,
                CommissionClawbackStatus::Failed,
            ], true)) {
                return ['clawback' => $clawback, 'notify_posted' => false, 'notify_review' => false, 'replay' => true];
            }

            $clawback->forceFill([
                'status' => CommissionClawbackStatus::Processing,
                'attempted_at' => now(),
            ])->save();

            /** @var Commission $commission */
            $commission = Commission::query()
                ->whereKey($clawback->commission_id)
                ->lockForUpdate()
                ->first();

            if ($commission === null) {
                return $this->quarantine($clawback, 'missing_commission', 'Commission row is missing.');
            }

            if ($commission->status !== CommissionStatus::Credited) {
                return $this->quarantine($clawback, 'invalid_commission_status', 'Commission is not credited.');
            }

            if ((int) $commission->salesperson_id !== (int) $clawback->salesperson_id) {
                return $this->quarantine($clawback, 'salesperson_mismatch', 'Commission salesperson mismatch.');
            }

            if (
                $clawback->fulfillment_id !== null
                && $commission->fulfillment_id !== null
                && (int) $clawback->fulfillment_id !== (int) $commission->fulfillment_id
            ) {
                return $this->quarantine($clawback, 'fulfillment_mismatch', 'Fulfillment relationship mismatch.');
            }

            $obligationAmount = LedgerMoney::normalize((string) $clawback->amount);
            $commissionAmount = LedgerMoney::normalize((string) $commission->commission_amount);

            if (! LedgerMoney::equals($obligationAmount, $commissionAmount)) {
                return $this->quarantine($clawback, 'obligation_amount_conflict', 'Obligation amount does not match commission.');
            }

            if (LedgerMoney::compare($obligationAmount, LedgerMoney::ZERO) !== 1) {
                return $this->quarantine($clawback, 'invalid_obligation_amount', 'Obligation amount must be positive.');
            }

            $creditId = $clawback->original_commission_credit_transaction_id
                ?? $commission->wallet_transaction_id;

            if ($creditId === null) {
                return $this->quarantine($clawback, 'missing_original_credit', 'Original commission credit is missing.');
            }

            /** @var WalletTransaction|null $credit */
            $credit = WalletTransaction::query()
                ->whereKey((int) $creditId)
                ->lockForUpdate()
                ->first();

            if ($credit === null) {
                return $this->quarantine($clawback, 'missing_original_credit', 'Original commission credit is missing.');
            }

            if ($credit->type !== WalletTransactionType::CommissionCredit
                || $credit->status !== WalletTransaction::STATUS_POSTED
                || $credit->direction !== WalletTransactionDirection::Credit
            ) {
                return $this->quarantine($clawback, 'invalid_original_credit', 'Original credit transaction is invalid.');
            }

            if (! LedgerMoney::equals($obligationAmount, (string) $credit->amount)) {
                return $this->quarantine($clawback, 'credit_amount_mismatch', 'Original credit amount does not match commission.');
            }

            $wallet = Wallet::query()
                ->whereKey($credit->wallet_id)
                ->lockForUpdate()
                ->first();

            if ($wallet === null || (int) $wallet->user_id !== (int) $clawback->salesperson_id) {
                return $this->quarantine($clawback, 'wrong_wallet', 'Commission credit wallet does not belong to salesperson.');
            }

            if ($wallet->type !== WalletType::Customer) {
                return $this->quarantine($clawback, 'wrong_wallet_type', 'Clawback wallet type is unsupported.');
            }

            $calc = $this->calculator->remainingForObligation(
                $commission,
                (int) $clawback->refund_wallet_transaction_id,
            );

            if (($calc['over_reversed'] ?? false) === true) {
                return $this->quarantine($clawback, 'conflicting_previous_reversal', 'Posted reversals exceed commission amount.');
            }

            $remaining = $calc['remaining'];

            if (LedgerMoney::equals($remaining, LedgerMoney::ZERO)) {
                $existing = WalletTransaction::query()
                    ->where('idempotency_key', CommissionClawbackPolicy::reversalIdempotencyKey(
                        (int) $commission->id,
                        (int) $clawback->refund_wallet_transaction_id,
                    ))
                    ->first();

                if ($existing !== null) {
                    $clawback->forceFill([
                        'status' => CommissionClawbackStatus::Posted,
                        'reversal_wallet_transaction_id' => $existing->id,
                        'posted_at' => $existing->posted_at ?? now(),
                        'failure_code' => null,
                        'failure_message_safe' => null,
                    ])->save();

                    return ['clawback' => $clawback->refresh(), 'notify_posted' => false, 'notify_review' => false, 'replay' => true];
                }

                return $this->quarantine($clawback, 'zero_remaining_without_reversal', 'Remaining reversal is zero without a posted reversal.');
            }

            if (! LedgerMoney::equals($remaining, $obligationAmount)) {
                return $this->quarantine($clawback, 'partial_remaining_unsupported', 'Partial reversal is not supported in M7.1.');
            }

            $orderNumber = null;
            if ($commission->order_id !== null) {
                $orderNumber = \App\Models\Order::query()
                    ->whereKey((int) $commission->order_id)
                    ->value('order_number');
                $orderNumber = is_string($orderNumber) ? $orderNumber : null;
            }

            $refundRef = WalletTransaction::query()
                ->whereKey((int) $clawback->refund_wallet_transaction_id)
                ->value('public_ref');
            $refundRef = is_string($refundRef) ? $refundRef : null;

            $result = $this->ledger->postCommissionReversal(
                wallet: $wallet,
                amount: $remaining,
                idempotencyKey: CommissionClawbackPolicy::reversalIdempotencyKey(
                    (int) $commission->id,
                    (int) $clawback->refund_wallet_transaction_id,
                ),
                meta: array_merge([
                    'commission_id' => $commission->id,
                    'clawback_public_ref' => $clawback->public_ref,
                    'refund_wallet_transaction_id' => $clawback->refund_wallet_transaction_id,
                    'original_commission_credit_transaction_id' => $credit->id,
                    'original_commission_credit_public_ref' => $credit->public_ref,
                    'policy_version' => $clawback->policy_version,
                ], ReceiptSnapshot::wrap([
                    'order_number' => $orderNumber,
                    'refund_public_ref' => $refundRef,
                    'currency' => 'USD',
                    'source_title' => 'Commission reversal',
                    'customer_safe_reason' => 'This commission was reversed because the related fulfillment was refunded.',
                ])),
                referenceType: Commission::class,
                referenceId: (int) $commission->id,
            );

            $reversal = $result->transaction;

            $clawback->forceFill([
                'status' => CommissionClawbackStatus::Posted,
                'reversal_wallet_transaction_id' => $reversal->id,
                'original_commission_credit_transaction_id' => $credit->id,
                'posted_at' => $reversal->posted_at ?? now(),
                'failure_code' => null,
                'failure_message_safe' => null,
            ])->save();

            if (! $result->wasReplayed) {
                app(SystemEventService::class)->record(
                    'commission.clawback.posted',
                    $clawback,
                    null,
                    [
                        'clawback_id' => $clawback->id,
                        'clawback_public_ref' => $clawback->public_ref,
                        'commission_id' => $commission->id,
                        'salesperson_id' => $clawback->salesperson_id,
                        'refund_wallet_transaction_id' => $clawback->refund_wallet_transaction_id,
                        'reversal_wallet_transaction_id' => $reversal->id,
                        'amount' => $remaining,
                    ],
                    'info',
                    true,
                );

                if (Schema::hasTable('activity_log')) {
                    activity()
                        ->inLog('payments')
                        ->event('commission.clawback.posted')
                        ->performedOn($commission)
                        ->withProperties([
                            'clawback_public_ref' => $clawback->public_ref,
                            'commission_id' => $commission->id,
                            'salesperson_id' => $clawback->salesperson_id,
                            'refund_wallet_transaction_id' => $clawback->refund_wallet_transaction_id,
                            'reversal_public_ref' => $reversal->public_ref,
                            'amount' => $remaining,
                            'currency' => $clawback->currency,
                        ])
                        ->log('Commission clawback reversal posted');
                }
            }

            CustomerFinancialBroadcaster::dispatch(
                (int) $clawback->salesperson_id,
                [
                    CustomerFinancialInvalidationReason::TransactionPosted,
                    CustomerFinancialInvalidationReason::CommissionStateChanged,
                ],
            );

            return [
                'clawback' => $clawback->refresh(),
                'notify_posted' => ! $result->wasReplayed,
                'notify_review' => false,
                'replay' => $result->wasReplayed,
                'reversal' => $reversal,
            ];
        });

        /** @var CommissionClawback $clawback */
        $clawback = $result['clawback'];

        if (($result['notify_posted'] ?? false) === true) {
            $this->notifyPosted($clawback, $result['reversal'] ?? null);
        }

        if (($result['notify_review'] ?? false) === true) {
            $this->notifyNeedsReview($clawback);
        }

        return $clawback;
    }

    /**
     * @return array{clawback: CommissionClawback, notify_posted: bool, notify_review: bool, replay: bool}
     */
    private function quarantine(CommissionClawback $clawback, string $code, string $safeMessage): array
    {
        $alreadyReview = $clawback->status === CommissionClawbackStatus::NeedsReview;

        $clawback->forceFill([
            'status' => CommissionClawbackStatus::NeedsReview,
            'failure_code' => $code,
            'failure_message_safe' => $safeMessage,
            'needs_review_at' => $clawback->needs_review_at ?? now(),
        ])->save();

        if (! $alreadyReview) {
            app(SystemEventService::class)->record(
                'commission.clawback.needs_review',
                $clawback,
                null,
                [
                    'clawback_id' => $clawback->id,
                    'clawback_public_ref' => $clawback->public_ref,
                    'commission_id' => $clawback->commission_id,
                    'salesperson_id' => $clawback->salesperson_id,
                    'failure_code' => $code,
                ],
                'warning',
                true,
            );
        }

        Log::warning('commission.clawback.needs_review', [
            'clawback_id' => $clawback->id,
            'failure_code' => $code,
        ]);

        return [
            'clawback' => $clawback->refresh(),
            'notify_posted' => false,
            'notify_review' => ! $alreadyReview,
            'replay' => false,
        ];
    }

    private function notifyPosted(CommissionClawback $clawback, ?WalletTransaction $reversal): void
    {
        DB::afterCommit(function () use ($clawback, $reversal): void {
            try {
                $salesperson = User::query()->find($clawback->salesperson_id);
                if ($salesperson === null) {
                    return;
                }

                $salesperson->notify(CommissionReversalPostedNotification::fromClawback(
                    $clawback,
                    $reversal,
                ));
            } catch (\Throwable $exception) {
                Log::warning('commission.clawback.notification_failed', [
                    'clawback_id' => $clawback->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function notifyNeedsReview(CommissionClawback $clawback): void
    {
        DB::afterCommit(function () use ($clawback): void {
            try {
                foreach (app(NotificationRecipientService::class)->usersWithPermission('process_commission_clawbacks') as $admin) {
                    $admin->notify(CommissionClawbackNeedsReviewNotification::fromClawback($clawback));
                }
            } catch (\Throwable $exception) {
                Log::warning('commission.clawback.needs_review_notification_failed', [
                    'clawback_id' => $clawback->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    public static function dispatchAfterCommit(int $clawbackId): void
    {
        DB::afterCommit(static function () use ($clawbackId): void {
            ProcessCommissionClawbackJob::dispatch($clawbackId);
        });
    }
}
