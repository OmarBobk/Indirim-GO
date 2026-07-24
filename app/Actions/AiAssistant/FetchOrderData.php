<?php

declare(strict_types=1);

namespace App\Actions\AiAssistant;

use App\Models\Order;

class FetchOrderData
{
    /**
     * @return array{
     *     order_id: int,
     *     order_number: string,
     *     status: string,
     *     currency: string,
     *     subtotal: string,
     *     fee: string,
     *     total: string,
     *     paid_at: string|null,
     *     created_at: string,
     *     customer: array{id: int, username: string, name: string, email: string},
     *     items: list<array{id: int, name: string, quantity: int, unit_price: string, line_total: string, status: string}>,
     *     fulfillments: list<array{id: int, status: string, provider: string, claimed_by: int|null, completed_at: string|null}>,
     * }|null
     */
    public function handle(string $orderNumber): ?array
    {
        $order = Order::query()
            ->where('order_number', trim($orderNumber))
            ->with([
                'user:id,username,name,email',
                'items:id,order_id,name,quantity,unit_price,line_total,status',
                'fulfillments:id,order_id,status,provider,claimed_by,completed_at',
            ])
            ->first();

        if ($order === null) {
            return null;
        }

        $user = $order->user;

        return [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status->value,
            'currency' => $order->currency,
            'subtotal' => (string) $order->subtotal,
            'fee' => (string) $order->fee,
            'total' => (string) $order->total,
            'paid_at' => $order->paid_at?->toDateTimeString(),
            'created_at' => $order->created_at?->toDateTimeString() ?? '',
            'customer' => [
                'id' => $user?->id ?? 0,
                'username' => (string) ($user?->username ?? ''),
                'name' => (string) ($user?->name ?? ''),
                'email' => (string) ($user?->email ?? ''),
            ],
            'items' => $order->items->map(fn ($item): array => [
                'id' => $item->id,
                'name' => $item->name,
                'quantity' => (int) $item->quantity,
                'unit_price' => (string) $item->unit_price,
                'line_total' => (string) $item->line_total,
                'status' => $item->status->value,
            ])->values()->all(),
            'fulfillments' => $order->fulfillments->map(fn ($fulfillment): array => [
                'id' => $fulfillment->id,
                'status' => $fulfillment->status->value,
                'provider' => $fulfillment->provider,
                'claimed_by' => $fulfillment->claimed_by,
                'completed_at' => $fulfillment->completed_at?->toDateTimeString(),
            ])->values()->all(),
        ];
    }
}
