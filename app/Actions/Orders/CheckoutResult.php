<?php

declare(strict_types=1);

namespace App\Actions\Orders;

use App\Models\Order;

final class CheckoutResult
{
    public function __construct(
        public readonly Order $order,
        public readonly bool $reusedExistingOrder,
    ) {}
}
