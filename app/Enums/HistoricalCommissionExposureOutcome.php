<?php

declare(strict_types=1);

namespace App\Enums;

enum HistoricalCommissionExposureOutcome: string
{
    case PlatformAbsorbed = 'platform_absorbed';
    case NotActionable = 'not_actionable';
    case InsufficientData = 'insufficient_data';
    case DuplicateOrInvalid = 'duplicate_or_invalid';
    case DeferredReview = 'deferred_review';

    public function labelKey(): string
    {
        return 'messages.historical_exposure_outcome_'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
