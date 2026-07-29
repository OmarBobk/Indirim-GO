<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who must act next on a pending financial workflow (customer-facing).
 */
enum FinancialPendingActor: string
{
    case WaitingStaff = 'waiting_staff';
    case NeedsCustomer = 'needs_customer';
    case Informational = 'informational';
}
