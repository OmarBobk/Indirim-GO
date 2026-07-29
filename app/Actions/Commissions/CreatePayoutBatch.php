<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\DTOs\WalletPosting;
use App\Enums\CommissionStatus;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\FulfillmentStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Commission;
use App\Models\PayoutBatch;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebsiteSetting;
use App\Notifications\CommissionCreditedNotification;
use App\Services\SystemEventService;
use App\Services\WalletLedger;
use App\Support\CustomerFinancialBroadcaster;
use App\Support\LedgerMoney;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class CreatePayoutBatch
{
    public function __construct(
        private readonly WalletLedger $ledger = new WalletLedger,
    ) {}

    /**
     * @param  array<int, int>  $commissionIds
     *
     * @throws ValidationException
     * @throws AuthorizationException
     */
    public function handle(User $actor, array $commissionIds, ?string $notes = null, bool $enforceMinAmount = true): PayoutBatch
    {
        if (! $actor->can('manage_settlements')) {
            throw new AuthorizationException(__('messages.payout_batch_unauthorized'));
        }

        $uniqueIds = array_values(array_unique(array_filter($commissionIds, fn ($id): bool => (int) $id > 0)));
        if ($uniqueIds === []) {
            throw ValidationException::withMessages([
                'commissions' => __('messages.payout_no_selection'),
            ]);
        }

        $resolvedCreatedBy = (int) $actor->id;

        return DB::transaction(function () use ($uniqueIds, $resolvedCreatedBy, $actor, $notes, $enforceMinAmount): PayoutBatch {
            $payoutWaitDays = WebsiteSetting::getCommissionPayoutWaitDays();
            $payoutMinAmount = WebsiteSetting::getCommissionPayoutMinAmount();
            $cutoff = CarbonImmutable::now()->subDays($payoutWaitDays);

            $commissions = Commission::query()
                ->select(['id', 'order_id', 'fulfillment_id', 'commission_amount', 'status', 'payout_batch_id', 'wallet_transaction_id'])
                ->whereIn('id', $uniqueIds)
                ->whereHas('order', function ($query) use ($cutoff): void {
                    $query->whereNotNull('paid_at')->where('paid_at', '<=', $cutoff);
                })
                ->lockForUpdate()
                ->with([
                    'fulfillment:id,status',
                    'order:id,paid_at',
                    'order.items:id,order_id',
                    'order.items.fulfillments:id,order_item_id,status',
                ])
                ->get();

            $eligible = $commissions
                ->filter(fn (Commission $commission): bool => $this->isEligibleForPayout($commission))
                ->values();

            if ($eligible->isEmpty()) {
                throw ValidationException::withMessages([
                    'commissions' => __('messages.payout_no_eligible_commissions'),
                ]);
            }

            $totalAmount = '0.00';
            foreach ($eligible as $eligibleCommission) {
                $totalAmount = LedgerMoney::add($totalAmount, (string) $eligibleCommission->commission_amount);
            }

            if ($enforceMinAmount && LedgerMoney::compare($totalAmount, number_format((float) $payoutMinAmount, 2, '.', '')) === -1) {
                throw ValidationException::withMessages([
                    'commissions' => __('messages.payout_min_total_not_reached', [
                        'amount' => number_format($payoutMinAmount, 2, '.', ''),
                    ]),
                ]);
            }

            $creditedAt = now();
            $batch = PayoutBatch::query()->create([
                'created_by' => $resolvedCreatedBy,
                'total_amount' => $totalAmount,
                'commission_count' => $eligible->count(),
                'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
                'paid_at' => $creditedAt,
            ]);
            $creditedCount = 0;
            $creditedTotal = '0.00';

            foreach ($eligible as $candidateCommission) {
                $commission = Commission::query()
                    ->whereKey($candidateCommission->id)
                    ->lockForUpdate()
                    ->with(['order:id,paid_at'])
                    ->first();

                if ($commission === null) {
                    continue;
                }

                if (! $this->isEligibleForPayout($commission)) {
                    continue;
                }

                if ($commission->wallet_transaction_id !== null) {
                    continue;
                }

                try {
                    $creditAmount = LedgerMoney::normalizePositive((string) $commission->commission_amount);
                } catch (\InvalidArgumentException) {
                    continue;
                }

                $salesperson = $commission->salesperson()->select(['id'])->first();
                if ($salesperson === null) {
                    continue;
                }

                $wallet = Wallet::forUser($salesperson);
                $wallet = Wallet::query()
                    ->whereKey($wallet->id)
                    ->lockForUpdate()
                    ->first();

                if ($wallet === null) {
                    continue;
                }

                $idempotencyKey = 'commission_credit:'.$commission->id;

                $result = $this->ledger->post(new WalletPosting(
                    wallet: $wallet,
                    type: WalletTransactionType::CommissionCredit,
                    direction: WalletTransactionDirection::Credit,
                    amount: $creditAmount,
                    idempotencyKey: $idempotencyKey,
                    meta: [
                        'commission_id' => $commission->id,
                        'order_id' => $commission->order_id,
                        'payout_batch_id' => $batch->id,
                    ],
                    referenceType: Commission::class,
                    referenceId: (int) $commission->id,
                ));

                $walletTransaction = $result->transaction;
                $wallet = $result->wallet;

                if (! $result->wasReplayed) {
                    app(SystemEventService::class)->record(
                        'wallet.commission.credited',
                        $walletTransaction,
                        $actor,
                        [
                            'wallet_id' => $wallet->id,
                            'commission_id' => $commission->id,
                            'order_id' => $commission->order_id,
                            'payout_batch_id' => $batch->id,
                            'amount' => $creditAmount,
                        ],
                        'info',
                        true,
                    );
                }

                $commission->update([
                    'status' => CommissionStatus::Credited,
                    'wallet_transaction_id' => $walletTransaction->id,
                    'payout_batch_id' => $batch->id,
                    'paid_at' => $creditedAt,
                    'paid_method' => 'wallet',
                ]);

                if (! $result->wasReplayed && Schema::hasTable('activity_log')) {
                    activity()
                        ->inLog('payments')
                        ->event('commission.credited')
                        ->performedOn($commission)
                        ->causedBy($actor)
                        ->withProperties([
                            'commission_id' => $commission->id,
                            'order_id' => $commission->order_id,
                            'salesperson_id' => $salesperson->id,
                            'amount' => $creditAmount,
                            'currency' => $wallet->currency,
                            'payout_batch_id' => $batch->id,
                            'wallet_id' => $wallet->id,
                            'transaction_id' => $walletTransaction->id,
                        ])
                        ->log('Commission credited to wallet');

                    activity()
                        ->inLog('payments')
                        ->event('wallet.credited')
                        ->performedOn($wallet)
                        ->causedBy($actor)
                        ->withProperties([
                            'wallet_id' => $wallet->id,
                            'user_id' => $wallet->user_id,
                            'amount' => $creditAmount,
                            'currency' => $wallet->currency,
                            'transaction_id' => $walletTransaction->id,
                            'source' => 'commission',
                            'commission_id' => $commission->id,
                            'payout_batch_id' => $batch->id,
                        ])
                        ->log('Wallet credited');
                }

                if (! $result->wasReplayed) {
                    CustomerFinancialBroadcaster::dispatch(
                        (int) $salesperson->id,
                        CustomerFinancialInvalidationReason::BalanceChanged,
                    );
                    CustomerFinancialBroadcaster::dispatch(
                        (int) $salesperson->id,
                        CustomerFinancialInvalidationReason::CommissionCredited,
                    );

                    $salespersonId = (int) $salesperson->id;
                    $commissionId = (int) $commission->id;
                    $walletCurrency = (string) $wallet->currency;
                    DB::afterCommit(function () use ($salespersonId, $commissionId, $creditAmount, $walletCurrency): void {
                        $recipient = User::query()->find($salespersonId);
                        if ($recipient === null) {
                            return;
                        }

                        $recipient->notify(CommissionCreditedNotification::fromCredited(
                            $commissionId,
                            (float) $creditAmount,
                            $walletCurrency
                        ));
                    });
                }

                $creditedCount++;
                $creditedTotal = LedgerMoney::add($creditedTotal, $creditAmount);
            }

            if ($creditedCount === 0) {
                throw ValidationException::withMessages([
                    'commissions' => __('messages.payout_no_eligible_commissions'),
                ]);
            }

            $batch->update([
                'total_amount' => $creditedTotal,
                'commission_count' => $creditedCount,
            ]);

            Log::info('Commission payout batch created.', [
                'batch_id' => $batch->id,
                'admin_id' => $resolvedCreatedBy,
                'total_amount' => $creditedTotal,
                'commission_count' => $creditedCount,
            ]);

            return $batch;
        });
    }

    private function isEligibleForPayout(Commission $commission): bool
    {
        if ($commission->status !== CommissionStatus::Pending) {
            return false;
        }

        if ($commission->payout_batch_id !== null) {
            return false;
        }

        if ($commission->wallet_transaction_id !== null) {
            return false;
        }

        if ($commission->fulfillment !== null) {
            return $commission->fulfillment->status === FulfillmentStatus::Completed;
        }

        $order = $commission->order;
        if ($order === null || $order->items->isEmpty()) {
            return false;
        }

        foreach ($order->items as $item) {
            if ($item->fulfillments->isEmpty()) {
                return false;
            }

            $allCompleted = $item->fulfillments->every(
                fn ($fulfillment): bool => $fulfillment->status === FulfillmentStatus::Completed
            );

            if (! $allCompleted) {
                return false;
            }
        }

        return true;
    }
}
