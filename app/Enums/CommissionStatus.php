<?php

declare(strict_types=1);

namespace App\Enums;

enum CommissionStatus: string
{
    case Pending = 'pending';
    case Credited = 'credited';
    case Failed = 'failed';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
