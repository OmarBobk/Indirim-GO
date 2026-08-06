<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Models\Order;
use App\Models\User;
use App\Support\LedgerMoney;

final class MobileOrderListItemFactory
{
    public function __construct(
        private readonly MobileCustomerOrderStatusProjector $projector,
    ) {}

    /**
     * @return array{
     *     order_number: string,
     *     created_at: string,
     *     paid_at: string|null,
     *     currency: string,
     *     total: array{amount: string, currency: string, display: array{currency: string, formatted: string}},
     *     payment_status: string,
     *     fulfillment_status: string,
     *     customer_state: string,
     *     title: string|null,
     *     item_count: int
     * }
     */
    public function fromOrder(Order $order, User $user): array
    {
        $projection = $this->projector->project($order);
        $money = MobileMoneyFactory::forUser($user);
        $items = $order->relationLoaded('items')
            ? $order->items->sortBy('id')->values()
            : collect();

        $firstItem = $items->first();
        $title = $firstItem !== null ? (string) $firstItem->name : null;

        return [
            'order_number' => (string) $order->order_number,
            'created_at' => $order->created_at?->toIso8601String() ?? now()->toIso8601String(),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'currency' => (string) $order->currency,
            'total' => $money->fromUsdDecimal(LedgerMoney::normalize((string) $order->total)),
            'payment_status' => $projection['payment_status'],
            'fulfillment_status' => $projection['fulfillment_status'],
            'customer_state' => $projection['customer_state'],
            'title' => $title !== '' ? $title : null,
            'item_count' => $items->count(),
        ];
    }
}
