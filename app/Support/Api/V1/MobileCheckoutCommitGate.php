<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Models\MobileCheckoutAttempt;
use App\Models\Order;

/**
 * Lifecycle hook inside the authoritative mobile checkout transaction.
 *
 * Invoked after paid order + wallet debit + required fulfillments exist in the
 * open transaction, and before attempt completion / outer commit.
 *
 * Production binding is a no-op. Tests may replace the binding in the container;
 * this hook is never driven by HTTP input, config, or environment variables.
 */
interface MobileCheckoutCommitGate
{
    public function afterAuthoritativePurchase(Order $order, MobileCheckoutAttempt $attempt): void;
}
