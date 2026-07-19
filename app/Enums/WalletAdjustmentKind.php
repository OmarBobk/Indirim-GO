<?php

declare(strict_types=1);

namespace App\Enums;

enum WalletAdjustmentKind: string
{
    case AdminCredit = 'admin_credit';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
