<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Models\MobileCheckoutAttempt;
use App\Models\Order;

final class NullMobileCheckoutCommitGate implements MobileCheckoutCommitGate
{
    public function afterAuthoritativePurchase(Order $order, MobileCheckoutAttempt $attempt): void
    {
        // Production no-op.
    }
}
