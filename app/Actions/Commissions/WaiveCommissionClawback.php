<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\CommissionClawbackDecisionStatus;
use App\Enums\CommissionClawbackDecisionType;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionClawbackWaiverReason;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\WalletTransactionType;
use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\CommissionClawbackWaiverApprovedNotification;
use App\Services\SystemEventService;
use App\Services\WalletLedger;
use App\Support\AdminOpsBroadcaster;
use App\Support\CommissionClawbackDecisionPublicRef;
use App\Support\Commissions\CommissionClawbackWaiverArithmetic;
use App\Support\Commissions\CommissionClawbackWaiverEligibility;
use App\Support\CustomerFinancialBroadcaster;
use App\Support\Financial\ReceiptSnapshot;
use App\Support\LedgerMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admin waiver of a commission clawback (M7.2.2).
 * Unposted full: status waived, no WTX. Posted: commission_clawback_waiver credit via WalletLedger.
 *
 * @return array{
 *     outcome: 'waived'|'denied'|'already_waived'|'replayed',
 *     clawback: CommissionClawback,
 *     decision: ?CommissionClawbackDecision,
 *     message_key: string,
 *     was_replayed: bool
 * }
 */
final class WaiveCommissionClawback
{
    public function __construct(
        private readonly WalletLedger $ledger = new WalletLedger,
        private readonly CommissionClawbackWaiverEligibility $eligibility = new CommissionClawbackWaiverEligibility,
        private readonly CommissionClawbackWaiverArithmetic $arithmetic = new CommissionClawbackWaiverArithmetic,
    ) {}

    /**
     * @return array{
     *     outcome: 'waived'|'denied'|'already_waived'|'replayed',
     *     clawback: CommissionClawback,
     *     decision: ?CommissionClawbackDecision,
     *     message_key: string,
     *     was_replayed: bool
     * }
     */
    public function handle(
        User $actor,
        CommissionClawback $clawback,
        string $reasonCode,
        ?string $requestedAmount = null,
        ?string $adminNote = null,
        ?string $idempotencyToken = null,
        bool $allowWhileDisputed = false,
    ): array {
        if (! $actor->can('waive_commission_clawbacks')) {
            throw new AuthorizationException(__('messages.clawback_waiver_unauthorized'));
        }

        $reason = CommissionClawbackWaiverReason::tryFrom(trim($reasonCode));
        if ($reason === null) {
            return [
                'outcome' => 'denied',
                'clawback' => $clawback,
                'decision' => null,
                'message_key' => 'messages.clawback_waiver_invalid_reason',
                'was_replayed' => false,
            ];
        }

        $note = $this->sanitizeNote($adminNote);
        $token = $this->normalizeToken($idempotencyToken);

        try {
            return DB::transaction(function () use ($actor, $clawback, $reason, $requestedAmount, $note, $token, $allowWhileDisputed): array {
                /** @var CommissionClawback $locked */
                $locked = CommissionClawback::query()
                    ->whereKey($clawback->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $existing = CommissionClawbackDecision::query()
                    ->where('idempotency_key', $token)
                    ->lockForUpdate()
                    ->first();

                if ($existing !== null) {
                    if ((int) $existing->commission_clawback_id !== (int) $locked->id
                        || $existing->type !== CommissionClawbackDecisionType::Waiver
                    ) {
                        return [
                            'outcome' => 'denied',
                            'clawback' => $locked,
                            'decision' => null,
                            'message_key' => 'messages.clawback_waiver_idempotency_conflict',
                            'was_replayed' => false,
                        ];
                    }

                    return [
                        'outcome' => 'replayed',
                        'clawback' => $locked->refresh(),
                        'decision' => $existing,
                        'message_key' => 'messages.clawback_waiver_approved',
                        'was_replayed' => true,
                    ];
                }

                // Serialize decision rows for this clawback before remaining math.
                CommissionClawbackDecision::query()
                    ->where('commission_clawback_id', $locked->id)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($locked->status === CommissionClawbackStatus::Waived) {
                    return [
                        'outcome' => 'already_waived',
                        'clawback' => $locked,
                        'decision' => null,
                        'message_key' => 'messages.clawback_waiver_already_waived',
                        'was_replayed' => false,
                    ];
                }

                if (! $allowWhileDisputed
                    && (new \App\Support\Commissions\CommissionClawbackDisputeState)->hasActiveDispute($locked)
                ) {
                    return [
                        'outcome' => 'denied',
                        'clawback' => $locked,
                        'decision' => null,
                        'message_key' => 'messages.clawback_waiver_disputed',
                        'was_replayed' => false,
                    ];
                }

                $decisionEligibility = $this->eligibility->decide($locked);
                if (! $decisionEligibility->allowed) {
                    return [
                        'outcome' => 'denied',
                        'clawback' => $locked,
                        'decision' => null,
                        'message_key' => $decisionEligibility->safeDenialKey !== ''
                            ? $decisionEligibility->safeDenialKey
                            : 'messages.clawback_waiver_unavailable',
                        'was_replayed' => false,
                    ];
                }

                if ($decisionEligibility->mode === 'unposted_full') {
                    return $this->applyUnpostedFull($actor, $locked, $reason, $note, $token);
                }

                return $this->applyPosted(
                    $actor,
                    $locked,
                    $reason,
                    $note,
                    $token,
                    $requestedAmount,
                    $decisionEligibility->maximumAmount,
                );
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueIdempotencyConflict($exception)) {
                $existing = CommissionClawbackDecision::query()
                    ->where('idempotency_key', $token)
                    ->first();

                if ($existing !== null && (int) $existing->commission_clawback_id === (int) $clawback->id) {
                    return [
                        'outcome' => 'replayed',
                        'clawback' => $clawback->fresh() ?? $clawback,
                        'decision' => $existing,
                        'message_key' => 'messages.clawback_waiver_approved',
                        'was_replayed' => true,
                    ];
                }
            }

            throw $exception;
        }
    }

    /**
     * @return array{outcome: string, clawback: CommissionClawback, decision: CommissionClawbackDecision, message_key: string, was_replayed: bool}
     */
    private function applyUnpostedFull(
        User $actor,
        CommissionClawback $locked,
        CommissionClawbackWaiverReason $reason,
        ?string $note,
        string $token,
    ): array {
        if ($locked->reversal_wallet_transaction_id !== null
            || $this->findMatchingPostedReversal($locked) !== null
        ) {
            return [
                'outcome' => 'denied',
                'clawback' => $locked,
                'decision' => null,
                'message_key' => 'messages.clawback_waiver_ambiguous_state',
                'was_replayed' => false,
            ];
        }

        $amount = LedgerMoney::normalize((string) $locked->amount);

        /** @var CommissionClawbackDecision $decision */
        $decision = CommissionClawbackDecisionPublicRef::withUniqueRetry(
            function (string $publicRef) use ($locked, $reason, $note, $actor, $token, $amount): CommissionClawbackDecision {
                return CommissionClawbackDecision::query()->create([
                    'public_ref' => $publicRef,
                    'commission_clawback_id' => $locked->id,
                    'type' => CommissionClawbackDecisionType::Waiver,
                    'status' => CommissionClawbackDecisionStatus::Recorded,
                    'amount' => $amount,
                    'reason_code' => $reason->value,
                    'admin_note' => $note,
                    'actor_id' => $actor->id,
                    'related_wallet_transaction_id' => null,
                    'idempotency_key' => $token,
                    'decided_at' => now(),
                ]);
            }
        );

        $previous = $locked->status;
        $locked->forceFill([
            'status' => CommissionClawbackStatus::Waived,
            'failure_code' => null,
            'failure_message_safe' => null,
        ])->save();

        $this->recordAudit($actor, $locked, $decision, $previous, null, full: true);
        $this->afterCommitSideEffects($locked, $decision, postedMoney: false);

        return [
            'outcome' => 'waived',
            'clawback' => $locked->refresh(),
            'decision' => $decision,
            'message_key' => 'messages.clawback_waiver_approved',
            'was_replayed' => false,
        ];
    }

    /**
     * @return array{outcome: string, clawback: CommissionClawback, decision: ?CommissionClawbackDecision, message_key: string, was_replayed: bool}
     */
    private function applyPosted(
        User $actor,
        CommissionClawback $locked,
        CommissionClawbackWaiverReason $reason,
        ?string $note,
        string $token,
        ?string $requestedAmount,
        string $maximumAmount,
    ): array {
        $reversal = null;
        if ($locked->reversal_wallet_transaction_id !== null) {
            $reversal = WalletTransaction::query()
                ->whereKey($locked->reversal_wallet_transaction_id)
                ->lockForUpdate()
                ->first();
        }
        $reversal ??= $this->findMatchingPostedReversal($locked, lock: true);

        if ($reversal === null
            || $reversal->type !== WalletTransactionType::CommissionReversal
            || $reversal->status !== WalletTransaction::STATUS_POSTED
        ) {
            return [
                'outcome' => 'denied',
                'clawback' => $locked,
                'decision' => null,
                'message_key' => 'messages.clawback_waiver_missing_reversal',
                'was_replayed' => false,
            ];
        }

        if ($locked->reversal_wallet_transaction_id === null) {
            // Ambiguous: matching reversal exists but obligation not linked — quarantine rather than invent link.
            $locked->forceFill([
                'status' => CommissionClawbackStatus::NeedsReview,
                'failure_code' => 'orphaned_reversal',
                'failure_message_safe' => 'A matching reversal exists while obligation was not linked.',
                'needs_review_at' => $locked->needs_review_at ?? now(),
            ])->save();

            return [
                'outcome' => 'denied',
                'clawback' => $locked->refresh(),
                'decision' => null,
                'message_key' => 'messages.clawback_waiver_ambiguous_state',
                'was_replayed' => false,
            ];
        }

        $remaining = $this->arithmetic->remainingWaivable($locked);
        if (LedgerMoney::compare($remaining, LedgerMoney::ZERO) !== 1) {
            return [
                'outcome' => 'denied',
                'clawback' => $locked,
                'decision' => null,
                'message_key' => 'messages.clawback_waiver_nothing_remaining',
                'was_replayed' => false,
            ];
        }

        $amount = $this->resolvePostedAmount($requestedAmount, $remaining, $maximumAmount);
        if ($amount === null) {
            return [
                'outcome' => 'denied',
                'clawback' => $locked,
                'decision' => null,
                'message_key' => 'messages.clawback_waiver_invalid_amount',
                'was_replayed' => false,
            ];
        }

        $wallet = Wallet::query()
            ->whereKey($reversal->wallet_id)
            ->lockForUpdate()
            ->firstOrFail();

        if ((int) $wallet->user_id !== (int) $locked->salesperson_id) {
            return [
                'outcome' => 'denied',
                'clawback' => $locked,
                'decision' => null,
                'message_key' => 'messages.clawback_waiver_wrong_wallet',
                'was_replayed' => false,
            ];
        }

        /** @var CommissionClawbackDecision $decision */
        $decision = CommissionClawbackDecisionPublicRef::withUniqueRetry(
            function (string $publicRef) use ($locked, $reason, $note, $actor, $token, $amount): CommissionClawbackDecision {
                return CommissionClawbackDecision::query()->create([
                    'public_ref' => $publicRef,
                    'commission_clawback_id' => $locked->id,
                    'type' => CommissionClawbackDecisionType::Waiver,
                    'status' => CommissionClawbackDecisionStatus::Posted,
                    'amount' => $amount,
                    'reason_code' => $reason->value,
                    'admin_note' => $note,
                    'actor_id' => $actor->id,
                    'related_wallet_transaction_id' => null,
                    'idempotency_key' => $token,
                    'decided_at' => now(),
                ]);
            }
        );

        $creditMeta = array_merge([
            'commission_id' => $locked->commission_id,
            'clawback_public_ref' => $locked->public_ref,
            'decision_public_ref' => $decision->public_ref,
            'reversal_wallet_transaction_id' => $reversal->id,
            'reversal_public_ref' => $reversal->public_ref,
            'original_commission_credit_transaction_id' => $locked->original_commission_credit_transaction_id,
            'policy_version' => $locked->policy_version,
            'reason_code' => $reason->value,
        ], ReceiptSnapshot::wrap([
            'currency' => strtoupper((string) ($locked->currency ?: 'USD')),
            'source_title' => 'Commission clawback waiver',
            'customer_safe_reason' => 'The platform waived part or all of a previous commission reversal.',
            'clawback_public_ref' => $locked->public_ref,
            'reversal_public_ref' => $reversal->public_ref,
            'decision_public_ref' => $decision->public_ref,
        ]));

        $result = $this->ledger->postCredit(
            wallet: $wallet,
            type: WalletTransactionType::CommissionClawbackWaiver,
            amount: $amount,
            idempotencyKey: CommissionClawbackWaiverArithmetic::walletIdempotencyKey((int) $decision->id),
            meta: $creditMeta,
            referenceType: CommissionClawbackDecision::class,
            referenceId: (int) $decision->id,
        );

        $credit = $result->transaction;
        $decision->forceFill([
            'related_wallet_transaction_id' => $credit->id,
            'status' => CommissionClawbackDecisionStatus::Posted,
        ])->save();

        $previous = $locked->status;
        $remainingAfter = $this->arithmetic->remainingWaivable($locked->fresh() ?? $locked);
        $full = LedgerMoney::compare($remainingAfter, LedgerMoney::ZERO) !== 1;
        $hasCorrectionCredits = LedgerMoney::compare(
            $this->arithmetic->postedCorrectionCredits($locked->fresh() ?? $locked),
            LedgerMoney::ZERO,
        ) === 1;

        if ($full && ! $hasCorrectionCredits) {
            $locked->forceFill([
                'status' => CommissionClawbackStatus::Waived,
            ])->save();
        } elseif ($locked->status !== CommissionClawbackStatus::Posted) {
            $locked->forceFill([
                'status' => CommissionClawbackStatus::Posted,
            ])->save();
        }

        $this->recordAudit($actor, $locked, $decision, $previous, $credit, full: $full);
        $this->afterCommitSideEffects($locked, $decision, postedMoney: true, credit: $credit);

        return [
            'outcome' => 'waived',
            'clawback' => $locked->refresh(),
            'decision' => $decision->refresh(),
            'message_key' => 'messages.clawback_waiver_approved',
            'was_replayed' => $result->wasReplayed,
        ];
    }

    private function resolvePostedAmount(?string $requestedAmount, string $remaining, string $maximum): ?string
    {
        $cap = LedgerMoney::compare($remaining, $maximum) === -1 ? $remaining : $maximum;

        if ($requestedAmount === null || trim($requestedAmount) === '') {
            return $cap;
        }

        try {
            $amount = LedgerMoney::normalizePositive($requestedAmount);
        } catch (\Throwable) {
            return null;
        }

        if (LedgerMoney::compare($amount, $cap) === 1) {
            return null;
        }

        return $amount;
    }

    private function findMatchingPostedReversal(CommissionClawback $clawback, bool $lock = false): ?WalletTransaction
    {
        $query = WalletTransaction::query()
            ->where('type', WalletTransactionType::CommissionReversal)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->where('idempotency_key', \App\Support\Commissions\CommissionClawbackPolicy::reversalIdempotencyKey(
                (int) $clawback->commission_id,
                (int) $clawback->refund_wallet_transaction_id,
            ));

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function afterCommitSideEffects(
        CommissionClawback $clawback,
        CommissionClawbackDecision $decision,
        bool $postedMoney,
        ?WalletTransaction $credit = null,
    ): void {
        $salespersonId = (int) $clawback->salesperson_id;
        $decisionId = (int) $decision->id;
        $clawbackId = (int) $clawback->id;

        DB::afterCommit(function () use ($salespersonId, $decisionId, $clawbackId, $postedMoney, $credit): void {
            try {
                $clawback = CommissionClawback::query()->find($clawbackId);
                $decision = CommissionClawbackDecision::query()->find($decisionId);
                if ($clawback === null || $decision === null) {
                    return;
                }

                $salesperson = User::query()->find($salespersonId);
                if ($salesperson !== null) {
                    $salesperson->notify(CommissionClawbackWaiverApprovedNotification::fromDecision(
                        $clawback,
                        $decision,
                        $credit,
                    ));
                }

                $reasons = [CustomerFinancialInvalidationReason::CommissionStateChanged];
                if ($postedMoney) {
                    $reasons[] = CustomerFinancialInvalidationReason::TransactionPosted;
                }
                CustomerFinancialBroadcaster::dispatch($salespersonId, $reasons);
                AdminOpsBroadcaster::dispatch('clawback-waived');
            } catch (\Throwable $exception) {
                Log::warning('commission.clawback.waiver_side_effects_failed', [
                    'clawback_id' => $clawbackId,
                    'message' => $exception->getMessage(),
                ]);
            }
        });
    }

    private function recordAudit(
        User $actor,
        CommissionClawback $clawback,
        CommissionClawbackDecision $decision,
        CommissionClawbackStatus|string $previous,
        ?WalletTransaction $credit,
        bool $full,
    ): void {
        $previousValue = $previous instanceof CommissionClawbackStatus ? $previous->value : $previous;
        $event = $credit !== null ? 'commission.clawback.waiver_posted' : 'commission.clawback.waived';

        try {
            app(SystemEventService::class)->record(
                $event,
                $clawback,
                $actor,
                [
                    'clawback_public_ref' => $clawback->public_ref,
                    'decision_public_ref' => $decision->public_ref,
                    'previous_status' => $previousValue,
                    'new_status' => $clawback->status instanceof CommissionClawbackStatus
                        ? $clawback->status->value
                        : (string) $clawback->status,
                    'amount' => $decision->amount !== null ? (string) $decision->amount : null,
                    'reason_code' => $decision->reason_code instanceof CommissionClawbackWaiverReason
                        ? $decision->reason_code->value
                        : (string) $decision->reason_code,
                    'full' => $full,
                    'related_wallet_transaction_public_ref' => $credit?->public_ref,
                ],
                'info',
                true,
            );
        } catch (\Throwable $exception) {
            Log::warning('commission.clawback.waiver_audit_failed', [
                'clawback_id' => $clawback->id,
                'message' => $exception->getMessage(),
            ]);
        }

        if (Schema::hasTable('activity_log')) {
            try {
                activity()
                    ->inLog('payments')
                    ->event($event)
                    ->performedOn($clawback)
                    ->causedBy($actor)
                    ->withProperties([
                        'clawback_public_ref' => $clawback->public_ref,
                        'decision_public_ref' => $decision->public_ref,
                        'amount' => $decision->amount !== null ? (string) $decision->amount : null,
                        'reason_code' => $decision->reason_code instanceof CommissionClawbackWaiverReason
                            ? $decision->reason_code->value
                            : (string) $decision->reason_code,
                        'admin_note' => $decision->admin_note,
                        'related_wallet_transaction_public_ref' => $credit?->public_ref,
                    ])
                    ->log('Commission clawback waiver recorded');
            } catch (\Throwable $exception) {
                Log::warning('commission.clawback.waiver_activity_failed', [
                    'clawback_id' => $clawback->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function sanitizeNote(?string $adminNote): ?string
    {
        if ($adminNote === null) {
            return null;
        }

        $trimmed = trim(strip_tags($adminNote));
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 500);
    }

    private function normalizeToken(?string $idempotencyToken): string
    {
        $token = is_string($idempotencyToken) ? trim($idempotencyToken) : '';
        if ($token === '' || mb_strlen($token) < 16 || mb_strlen($token) > 128) {
            return 'waiver:'.Str::uuid()->toString();
        }

        if (! preg_match('/^[A-Za-z0-9_.:-]+$/', $token)) {
            return 'waiver:'.Str::uuid()->toString();
        }

        return 'waiver:'.$token;
    }

    private function isUniqueIdempotencyConflict(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'idempotency_key')
            && (str_contains($message, 'unique') || str_contains($message, 'duplicate'));
    }
}
