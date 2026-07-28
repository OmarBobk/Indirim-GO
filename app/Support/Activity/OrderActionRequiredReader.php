<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\DTOs\CustomerActivityDestination;
use App\DTOs\CustomerActivityDTO;
use App\Enums\CustomerActivityCategory;
use App\Enums\CustomerActivityDestinationType;
use App\Enums\CustomerActivityImportance;
use App\Enums\CustomerActivityStatusToken;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\CustomerOrderFulfillmentClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Customer-owned order/fulfillment states that need attention (classifier-aligned).
 */
final class OrderActionRequiredReader
{
    public const MAX_ITEMS = 20;

    public function __construct(
        private readonly CustomerOrderFulfillmentClassifier $classifier,
    ) {}

    /**
     * @return list<CustomerActivityDTO>
     */
    public function forUser(User $user, ?CustomerActivityCategory $category = null): array
    {
        if ($category !== null && $category !== CustomerActivityCategory::Orders) {
            return [];
        }

        $query = Order::query()
            ->where('user_id', $user->id);

        $this->classifier->selectClassification($query);
        $this->classifier->applyFilter($query, CustomerOrderFulfillmentClassifier::NEEDS_ATTENTION);

        /** @var Collection<int, Order> $orders */
        $orders = $query
            ->with(['fulfillments' => fn ($q) => $q->select([
                'id', 'order_id', 'order_item_id', 'status', 'meta', 'updated_at', 'created_at',
            ])])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->limit(self::MAX_ITEMS)
            ->get();

        return $orders
            ->map(fn (Order $order): CustomerActivityDTO => $this->mapOrder($order))
            ->all();
    }

    private function mapOrder(Order $order): CustomerActivityDTO
    {
        $orderId = (string) $order->id;
        $orderNumber = (string) $order->order_number;
        $isPaymentIssue = in_array($order->status, [OrderStatus::PendingPayment, OrderStatus::Failed], true);

        if ($isPaymentIssue) {
            $stableKey = 'action:order:'.$orderId.':payment';

            return new CustomerActivityDTO(
                id: $stableKey,
                stableKey: $stableKey,
                sourceType: 'Order',
                sourceId: $orderId,
                dedupeKey: 'payment:'.$orderId,
                groupKey: 'order:'.$orderId,
                category: CustomerActivityCategory::Orders,
                importance: CustomerActivityImportance::Urgent,
                statusToken: CustomerActivityStatusToken::Danger,
                title: __('messages.activity_action_order_payment_title'),
                description: __('messages.activity_action_order_payment_description', [
                    'order_number' => $orderNumber,
                ]),
                occurredAt: $this->asCarbon($order->updated_at ?? $order->created_at),
                readAt: null,
                isUnread: false,
                requiresAction: true,
                actionLabel: __('messages.activity_action_return_to_cart'),
                destination: new CustomerActivityDestination(CustomerActivityDestinationType::Cart),
                secondaryMeta: [
                    'order_number' => $orderNumber,
                    'related_dedupe_keys' => 'payment:'.$orderId,
                ],
                money: null,
                iconKey: 'shopping-cart',
            );
        }

        $actionable = $order->fulfillments
            ->filter(fn (Fulfillment $fulfillment): bool => $this->isCustomerActionableFailure($fulfillment))
            ->sortBy('id')
            ->values();

        $primary = $actionable->first();
        $relatedKeys = $actionable
            ->map(fn (Fulfillment $fulfillment): string => 'fulfillment:'.$fulfillment->id)
            ->implode(',');

        $primaryFulfillmentId = $primary !== null ? (string) $primary->id : $orderId;
        $stableKey = 'action:order:'.$orderId.':customer_action';
        $dedupeKey = $primary !== null
            ? 'fulfillment:'.$primaryFulfillmentId
            : 'order:'.$orderId.':customer_action';

        return new CustomerActivityDTO(
            id: $stableKey,
            stableKey: $stableKey,
            sourceType: $primary !== null ? 'Fulfillment' : 'Order',
            sourceId: $primaryFulfillmentId,
            dedupeKey: $dedupeKey,
            groupKey: 'order:'.$orderId,
            category: CustomerActivityCategory::Orders,
            importance: CustomerActivityImportance::Urgent,
            statusToken: CustomerActivityStatusToken::Danger,
            title: __('messages.activity_action_order_failed_title'),
            description: __('messages.activity_action_order_failed_description', [
                'order_number' => $orderNumber,
            ]),
            occurredAt: $this->asCarbon(
                $primary?->updated_at ?? $order->updated_at ?? $order->created_at
            ),
            readAt: null,
            isUnread: false,
            requiresAction: true,
            actionLabel: __('messages.activity_action_view_order'),
            destination: new CustomerActivityDestination(
                CustomerActivityDestinationType::OrderDetail,
                ['order_number' => $orderNumber]
            ),
            secondaryMeta: array_filter([
                'order_number' => $orderNumber,
                'related_dedupe_keys' => $relatedKeys !== '' ? $relatedKeys : null,
                'actionable_units' => (string) $actionable->count(),
            ], fn (mixed $value): bool => $value !== null && $value !== ''),
            money: null,
            iconKey: 'exclamation-triangle',
        );
    }

    private function isCustomerActionableFailure(Fulfillment $fulfillment): bool
    {
        if ($fulfillment->status !== FulfillmentStatus::Failed) {
            return false;
        }

        $refundStatus = data_get($fulfillment->meta, 'refund.status');

        return ! in_array($refundStatus, [
            WalletTransaction::STATUS_PENDING,
            WalletTransaction::STATUS_POSTED,
        ], true);
    }

    private function asCarbon(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        return Carbon::parse((string) $value);
    }
}
