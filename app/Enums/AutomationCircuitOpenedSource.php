<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationCircuitOpenedSource: string
{
    case Auto = 'auto';
    case Manual = 'manual';
    case Probe = 'probe';
    case Threshold = 'threshold';
}
