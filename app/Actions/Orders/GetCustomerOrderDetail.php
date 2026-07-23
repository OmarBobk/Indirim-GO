<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;
use App\Support\CustomerOrderFulfillmentClassifier;

/**
 * Single query owner for the customer Order Details read model.
 * Ownership, eager-loading shape, and classification selection live here.
 */
final class GetCustomerOrderDetail
{
    public function __construct(
        private readonly CustomerOrderFulfillmentClassifier $classifier,
    ) {}

    /**
     * @return list<string>
     */
    public static function eagerLoadRelations(): array
    {
        return [
            'items.fulfillments',
            'items.package.requirements',
            'items.product' => fn ($query) => $query->select(['id', 'package_id', 'is_active']),
        ];
    }

    public function handle(Order $order, int $userId): Order
    {
        if ((int) $order->user_id !== $userId) {
            abort(403);
        }

        $query = Order::query()
            ->whereKey($order->getKey())
            ->where('user_id', $userId)
            ->with(self::eagerLoadRelations());

        $this->classifier->selectClassification($query);

        $loaded = $query->first();

        if ($loaded === null) {
            abort(403);
        }

        return $loaded;
    }
}
