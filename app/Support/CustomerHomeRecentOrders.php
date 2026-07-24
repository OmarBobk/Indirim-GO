<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;

/**
 * Compact recent-order rows for authenticated home (trust/status, not full orders UI).
 */
final class CustomerHomeRecentOrders
{
    public const LIMIT = 3;

    /**
     * @return list<array{
     *     id: int,
     *     order_number: string,
     *     status: string,
     *     status_label: string,
     *     total_label: string,
     *     href: string,
     * }>
     */
    public static function forUser(User $user, int $limit = self::LIMIT): array
    {
        $money = FrontendMoney::for($user);

        return Order::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->limit($limit)
            ->get(['id', 'order_number', 'status', 'total', 'currency'])
            ->map(function (Order $order) use ($money): array {
                $status = $order->status instanceof OrderStatus
                    ? $order->status
                    : OrderStatus::from((string) $order->status);

                return [
                    'id' => (int) $order->id,
                    'order_number' => (string) $order->order_number,
                    'status' => $status->value,
                    'status_label' => __('messages.order_status_'.$status->value),
                    'total_label' => $money->format((float) $order->total, (string) ($order->currency ?: 'USD'), 2),
                    'href' => route('orders.show', $order->order_number),
                ];
            })
            ->all();
    }
}
