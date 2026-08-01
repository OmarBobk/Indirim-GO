<?php

declare(strict_types=1);

namespace App\Enums;

enum CommissionClawbackDecisionStatus: string
{
    /** Unposted full waiver / dispute resolution recorded — no wallet movement. */
    case Recorded = 'recorded';
    /** Posted waiver or correction with linked wallet credit. */
    case Posted = 'posted';
    /** Active dispute opened (M7.2.3). */
    case Open = 'open';
}
