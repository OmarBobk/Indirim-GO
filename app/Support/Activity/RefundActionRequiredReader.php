<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\DTOs\CustomerActivityDestination;
use App\DTOs\CustomerActivityDTO;
use App\DTOs\CustomerActivityMoney;
use App\Enums\CustomerActivityCategory;
use App\Enums\CustomerActivityDestinationType;
use App\Enums\CustomerActivityImportance;
use App\Enums\CustomerActivityStatusToken;
use App\Enums\FulfillmentStatus;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;

/**
 * Unresolved customer refund states for Activity (ledger + failed fulfillment).
 */
final class RefundActionRequiredReader
{
    public const MAX_ITEMS = 10;

    /**
     * @return list<CustomerActivityDTO>
     */
    public function forUser(User $user, ?CustomerActivityCategory $category = null): array
    {
        if ($category !== null && $category !== CustomerActivityCategory::Orders) {
            return [];
        }

        $walletIds = Wallet::query()
            ->where('user_id', $user->id)
            ->pluck('id');

        if ($walletIds->isEmpty()) {
            return [];
        }

        $transactions = WalletTransaction::query()
            ->whereIn('wallet_id', $walletIds)
            ->where('type', WalletTransactionType::Refund)
            ->where('status', WalletTransaction::STATUS_REJECTED)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ITEMS)
            ->get(['id', 'amount', 'status', 'meta', 'created_at', 'wallet_id', 'public_ref']);

        $fulfillmentIds = $transactions
            ->map(fn (WalletTransaction $tx): mixed => data_get($tx->meta, 'fulfillment_id'))
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $failedFulfillmentIds = $fulfillmentIds === []
            ? []
            : Fulfillment::query()
                ->whereIn('id', $fulfillmentIds)
                ->where('status', FulfillmentStatus::Failed)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();

        $failedLookup = array_fill_keys($failedFulfillmentIds, true);

        return $transactions
            ->filter(function (WalletTransaction $tx) use ($failedLookup): bool {
                $fulfillmentId = data_get($tx->meta, 'fulfillment_id');

                if (! is_numeric($fulfillmentId)) {
                    return false;
                }

                return isset($failedLookup[(int) $fulfillmentId]);
            })
            ->map(fn (WalletTransaction $tx): CustomerActivityDTO => $this->map($tx))
            ->values()
            ->all();
    }

    private function map(WalletTransaction $transaction): CustomerActivityDTO
    {
        $id = (string) $transaction->id;
        $stableKey = 'action:refund:'.$id.':rejected';
        $orderNumber = data_get($transaction->meta, 'order_number');
        $orderNumber = is_string($orderNumber) && trim($orderNumber) !== '' ? trim($orderNumber) : null;
        $currency = strtoupper((string) (data_get($transaction->meta, 'currency') ?: 'USD'));
        $publicRef = filled($transaction->public_ref) ? (string) $transaction->public_ref : null;

        $destination = $publicRef !== null
            ? new CustomerActivityDestination(
                CustomerActivityDestinationType::WalletRefund,
                ['public_ref' => $publicRef]
            )
            : ($orderNumber !== null
                ? new CustomerActivityDestination(
                    CustomerActivityDestinationType::OrderDetail,
                    ['order_number' => $orderNumber]
                )
                : new CustomerActivityDestination(CustomerActivityDestinationType::Orders));

        return new CustomerActivityDTO(
            id: $stableKey,
            stableKey: $stableKey,
            sourceType: 'WalletTransaction',
            sourceId: $id,
            dedupeKey: 'refund:'.$id,
            groupKey: $orderNumber !== null ? 'order:'.$orderNumber : null,
            category: CustomerActivityCategory::Orders,
            importance: CustomerActivityImportance::Attention,
            statusToken: CustomerActivityStatusToken::Warning,
            title: __('messages.activity_action_refund_rejected_title'),
            description: $orderNumber !== null
                ? __('messages.activity_action_refund_rejected_description_order', ['order_number' => $orderNumber])
                : __('messages.activity_action_refund_rejected_description'),
            occurredAt: $transaction->created_at instanceof Carbon
                ? $transaction->created_at
                : Carbon::parse((string) $transaction->created_at),
            readAt: null,
            isUnread: false,
            requiresAction: true,
            actionLabel: __('messages.activity_action_view_refund'),
            destination: $destination,
            secondaryMeta: array_filter([
                'order_number' => $orderNumber,
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            money: new CustomerActivityMoney(
                amount: (string) $transaction->amount,
                currency: $currency,
                direction: 'credit',
                visible: true,
            ),
            iconKey: 'arrow-uturn-left',
        );
    }
}
