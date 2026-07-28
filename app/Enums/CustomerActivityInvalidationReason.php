<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Allowlisted customer Activity invalidation reasons (broadcast payload only).
 */
enum CustomerActivityInvalidationReason: string
{
    case OrderPaid = 'order_paid';
    case TopupStateChanged = 'topup_state_changed';
    case RefundStateChanged = 'refund_state_changed';
    case FulfillmentStateChanged = 'fulfillment_state_changed';
    case AccountStateChanged = 'account_state_changed';
}
