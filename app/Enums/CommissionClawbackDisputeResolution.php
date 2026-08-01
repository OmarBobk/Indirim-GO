<?php

declare(strict_types=1);

namespace App\Enums;

enum CommissionClawbackDisputeResolution: string
{
    case Rejected = 'rejected';
    case AcceptedAsWaiver = 'accepted_as_waiver';
    case AcceptedAsCorrection = 'accepted_as_correction';
    case Withdrawn = 'withdrawn';

    public function labelKey(): string
    {
        return 'messages.clawback_dispute_resolution_'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
