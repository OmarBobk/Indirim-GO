<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Allowlisted customer financial invalidation reasons (broadcast payload only).
 */
enum CustomerFinancialInvalidationReason: string
{
    case BalanceChanged = 'balance_changed';
    case TopupStateChanged = 'topup_state_changed';
    case RefundStateChanged = 'refund_state_changed';
    case CommissionCredited = 'commission_credited';
}
