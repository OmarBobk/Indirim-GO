<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Models\OrderItem;
use App\Models\Package;

class ResolveFulfillmentProvider
{
    public function handle(OrderItem $orderItem): string
    {
        $provider = $orderItem->package?->fulfillment_provider;

        if ($provider === null || $provider === '') {
            if ($orderItem->package_id !== null) {
                $provider = Package::query()
                    ->whereKey($orderItem->package_id)
                    ->value('fulfillment_provider');
            }
        }

        if ($provider === null || $provider === '') {
            return 'manual';
        }

        return $provider;
    }
}
