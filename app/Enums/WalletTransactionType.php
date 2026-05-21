<?php

namespace App\Enums;

enum WalletTransactionType: string
{
    case Topup = 'topup';
    case Purchase = 'purchase';
    case Refund = 'refund';
    case Adjustment = 'adjustment';
    case Settlement = 'settlement';
    case CommissionCredit = 'commission_credit';

    /**
     * Get all enum values as an array.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
