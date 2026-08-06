<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationRunLiveness: string
{
    case Healthy = 'healthy';
    case Slow = 'slow';
    case Stale = 'stale';
    case WaitingSupplier = 'waiting_supplier';
    case ScheduledReconcile = 'scheduled_reconcile';
    case NeedsAttention = 'needs_attention';
    case Unknown = 'unknown';
}
