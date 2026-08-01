<?php

declare(strict_types=1);

namespace App\Enums;

enum CommissionClawbackWaiverReason: string
{
    case CommercialGoodwill = 'commercial_goodwill';
    case OperationalException = 'operational_exception';
    case ManagementDecision = 'management_decision';
    case SalespersonRelief = 'salesperson_relief';
    case OtherApproved = 'other_approved';

    public function labelKey(): string
    {
        return 'messages.clawback_waiver_reason_'.$this->value;
    }

    public function descriptionKey(): string
    {
        return 'messages.clawback_waiver_reason_'.$this->value.'_hint';
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
