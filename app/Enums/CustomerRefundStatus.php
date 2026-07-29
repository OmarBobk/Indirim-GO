<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Customer-facing refund workspace lifecycle (maps from WalletTransaction refund status + meta).
 */
enum CustomerRefundStatus: string
{
    case UnderReview = 'under_review';
    case Refunded = 'refunded';
    case NeedsAction = 'needs_action';
    case Closed = 'closed';
    case IntegrityAnomaly = 'integrity_anomaly';
}
