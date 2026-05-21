<?php

declare(strict_types=1);

namespace App\Enums;

enum PayoutRequestStatus: string
{
    case Pending = 'pending';
    case Processed = 'processed';
}
