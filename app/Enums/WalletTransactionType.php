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
    case CommissionReversal = 'commission_reversal';
    case CommissionClawbackWaiver = 'commission_clawback_waiver';
    case CommissionReversalCorrection = 'commission_reversal_correction';

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
