<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminDashboardVariant: string
{
    case Full = 'full';
    case Finance = 'finance';
    case Fulfillment = 'fulfillment';
    case Orders = 'orders';

    /**
     * Exception card keys shown for this dashboard variant.
     * Null means all keys except orders_with_failures (full admin breadth).
     *
     * @return list<string>|null
     */
    public function visibleExceptionKeys(): ?array
    {
        return match ($this) {
            self::Finance => ['pending_refunds', 'pending_topups', 'pending_payouts'],
            self::Fulfillment => ['fulfillment_queue', 'failed_fulfillments', 'automation_needs_review'],
            self::Orders => ['orders_with_failures'],
            self::Full => null,
        };
    }
}
