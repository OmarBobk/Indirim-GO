<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Enums\OrderStatus;
use App\Enums\ProductAmountMode;
use App\Models\Order;
use App\Models\User;

final class MobilePurchaseReceiptFactory
{
    /**
     * @return array{
     *     order_number: string,
     *     status: string,
     *     payment_status: string,
     *     currency: string,
     *     total: array{amount: string, currency: string, display: array{currency: string, formatted: string}},
     *     paid_at: string|null,
     *     items: list<array{
     *         product_id: int|null,
     *         package_id: int|null,
     *         name: string,
     *         amount_mode: string,
     *         quantity: int,
     *         requested_amount: int|null,
     *         line_total: array{amount: string, currency: string, display: array{currency: string, formatted: string}}
     *     }>
     * }
     */
    public function fromOrder(Order $order, User $user): array
    {
        $order->loadMissing('items');
        $money = MobileMoneyFactory::forUser($user);
        $isPaid = $order->status === OrderStatus::Paid
            || $order->status === OrderStatus::Processing
            || $order->status === OrderStatus::Fulfilled;

        $items = $order->items->map(function ($item) use ($money): array {
            $mode = $item->amount_mode instanceof ProductAmountMode
                ? $item->amount_mode->value
                : (string) ($item->amount_mode ?? ProductAmountMode::Fixed->value);

            return [
                'product_id' => $item->product_id !== null ? (int) $item->product_id : null,
                'package_id' => $item->package_id !== null ? (int) $item->package_id : null,
                'name' => (string) $item->name,
                'amount_mode' => $mode,
                'quantity' => (int) $item->quantity,
                'requested_amount' => $item->requested_amount !== null ? (int) $item->requested_amount : null,
                'line_total' => $money->fromUsdAmount((float) $item->line_total),
            ];
        })->values()->all();

        return [
            'order_number' => (string) $order->order_number,
            'status' => $order->status->value,
            'payment_status' => $isPaid ? 'paid' : $order->status->value,
            'currency' => (string) $order->currency,
            'total' => $money->fromUsdAmount((float) $order->total),
            'paid_at' => $order->paid_at?->toIso8601String(),
            'items' => $items,
        ];
    }
}
