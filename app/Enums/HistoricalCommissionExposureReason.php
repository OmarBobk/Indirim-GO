<?php

declare(strict_types=1);

namespace App\Enums;

enum HistoricalCommissionExposureReason: string
{
    case PrePolicyRefund = 'pre_policy_refund';
    case FeatureGap = 'feature_gap';
    case SourceIncomplete = 'source_incomplete';
    case DuplicateCandidate = 'duplicate_candidate';
    case OperationalDeferral = 'operational_deferral';
    case OtherReviewed = 'other_reviewed';

    public function labelKey(): string
    {
        return 'messages.historical_exposure_reason_'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
