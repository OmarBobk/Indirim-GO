<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Operational state of an already-granted credit facility.
 * Null on the wallet when credit_enabled=false (no facility). Active/Suspended only when granted.
 */
enum CreditFacilityStatus: string
{
    case Active = 'active';
    case Suspended = 'suspended';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function label(): string
    {
        return match ($this) {
            self::Active => __('messages.credit_facility_status_active'),
            self::Suspended => __('messages.credit_facility_status_suspended'),
        };
    }
}
