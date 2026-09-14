<?php

declare(strict_types=1);

namespace App\Enums;

enum MobileTopupAttemptStatus: string
{
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
