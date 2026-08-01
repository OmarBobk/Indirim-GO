<?php

declare(strict_types=1);

namespace App\Enums;

enum CommissionClawbackDecisionType: string
{
    case Waiver = 'waiver';
    case Correction = 'correction';
    case DisputeOpened = 'dispute_opened';
    case DisputeResolved = 'dispute_resolved';
}
