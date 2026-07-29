<?php

declare(strict_types=1);

namespace App\Actions\Wallets;

use App\DTOs\WalletAdjustmentResult;
use App\DTOs\WalletPosting;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\WalletAdjustmentKind;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\SystemEventService;
use App\Services\WalletLedger;
use App\Support\CustomerFinancialBroadcaster;
use App\Support\Financial\ReceiptSnapshot;
use App\Support\LedgerMoney;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

final class AdjustWallet
{
    public function __construct(
        private readonly WalletLedger $ledger,
        private readonly SystemEventService $systemEvents,
    ) {}

    public function handle(
        User $actor,
        User $targetUser,
        string $amount,
        string $idempotencyKey,
        WalletAdjustmentKind $kind = WalletAdjustmentKind::AdminCredit,
        ?string $reason = null,
        ?string $ipAddress = null,
    ): WalletAdjustmentResult {
        if (! $actor->can('adjust_wallets')) {
            throw new AuthorizationException(__('messages.wallet_adjustment_unauthorized'));
        }

        if ($kind !== WalletAdjustmentKind::AdminCredit) {
            throw ValidationException::withMessages([
                'kind' => __('messages.wallet_adjustment_kind_not_supported'),
            ]);
        }

        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey === '') {
            throw ValidationException::withMessages([
                'idempotency_key' => __('messages.wallet_adjustment_idempotency_required'),
            ]);
        }

        try {
            $normalizedAmount = LedgerMoney::normalizePositive(trim($amount));
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'amount' => __('messages.wallet_adjustment_amount_invalid'),
            ]);
        }

        $reason = $reason !== null ? trim($reason) : null;
        if ($reason === '') {
            $reason = null;
        }

        return DB::transaction(function () use (
            $actor,
            $targetUser,
            $normalizedAmount,
            $idempotencyKey,
            $kind,
            $reason,
            $ipAddress,
        ): WalletAdjustmentResult {
            $wallet = Wallet::forUser($targetUser);

            if ($wallet->type !== WalletType::Customer) {
                throw ValidationException::withMessages([
                    'wallet' => __('messages.wallet_adjustment_customer_only'),
                ]);
            }

            $meta = array_merge(array_filter([
                'adjustment_kind' => $kind->value,
                'actor_id' => $actor->id,
                'actor_name' => $actor->name,
                'target_user_id' => $targetUser->id,
                'target_user_name' => $targetUser->name,
                'currency' => $wallet->currency,
                'reason' => $reason,
                'ip_address' => $ipAddress,
            ], fn (mixed $value): bool => $value !== null && $value !== ''), ReceiptSnapshot::wrap([
                'customer_safe_reason' => $reason,
                'currency' => is_string($wallet->currency) ? strtoupper($wallet->currency) : 'USD',
            ]));

            $result = $this->ledger->post(new WalletPosting(
                wallet: $wallet,
                type: WalletTransactionType::Adjustment,
                direction: WalletTransactionDirection::Credit,
                amount: $normalizedAmount,
                idempotencyKey: $idempotencyKey,
                meta: $meta,
            ));

            $transaction = $result->transaction;

            if (! $result->wasReplayed) {
                CustomerFinancialBroadcaster::dispatch(
                    (int) $targetUser->id,
                    CustomerFinancialInvalidationReason::BalanceChanged,
                );
            }

            $this->systemEvents->record(
                'wallet.adjustment.posted',
                $transaction,
                $actor,
                [
                    'wallet_id' => $wallet->id,
                    'target_user_id' => $targetUser->id,
                    'amount' => (float) $normalizedAmount,
                    'currency' => $wallet->currency,
                    'adjustment_kind' => $kind->value,
                    'previous_balance' => $result->previousBalance,
                    'new_balance' => $result->newBalance,
                    'transaction_id' => $transaction->id,
                    'reason' => $reason,
                    'ip_address' => $ipAddress,
                ],
                'info',
                true,
            );

            if (Schema::hasTable('activity_log')) {
                $currency = $wallet->currency;
                $description = sprintf(
                    'Admin %s added %s %s to %s\'s wallet.',
                    $actor->name,
                    $normalizedAmount,
                    $currency,
                    $targetUser->name,
                );

                activity()
                    ->inLog('payments')
                    ->event('wallet.adjusted')
                    ->performedOn($transaction)
                    ->causedBy($actor)
                    ->withProperties([
                        'wallet_id' => $wallet->id,
                        'target_user_id' => $targetUser->id,
                        'actor_id' => $actor->id,
                        'amount' => $normalizedAmount,
                        'currency' => $currency,
                        'adjustment_kind' => $kind->value,
                        'reason' => $reason,
                        'previous_balance' => $result->previousBalance,
                        'new_balance' => $result->newBalance,
                        'transaction_id' => $transaction->id,
                        'idempotency_key' => $idempotencyKey,
                        'ip_address' => $ipAddress,
                    ])
                    ->log($description);

                activity()
                    ->inLog('payments')
                    ->event('wallet.credited')
                    ->performedOn($wallet)
                    ->causedBy($actor)
                    ->withProperties([
                        'wallet_id' => $wallet->id,
                        'user_id' => $wallet->user_id,
                        'amount' => $normalizedAmount,
                        'currency' => $currency,
                        'transaction_id' => $transaction->id,
                        'source' => 'adjustment',
                        'adjustment_kind' => $kind->value,
                    ])
                    ->log('Wallet credited');
            }

            return $result;
        });
    }
}
