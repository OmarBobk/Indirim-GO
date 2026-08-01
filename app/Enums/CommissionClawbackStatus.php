<?php

declare(strict_types=1);

namespace App\Enums;

enum CommissionClawbackStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Posted = 'posted';
    case NeedsReview = 'needs_review';
    case Failed = 'failed';
    case Waived = 'waived';
}
