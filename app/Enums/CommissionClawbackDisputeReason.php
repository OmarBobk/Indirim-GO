<?php

declare(strict_types=1);

namespace App\Enums;

enum CommissionClawbackDisputeReason: string
{
    case SourceRelationshipQuestioned = 'source_relationship_questioned';
    case RefundValidityQuestioned = 'refund_validity_questioned';
    case CommissionAmountQuestioned = 'commission_amount_questioned';
    case ReversalAmountQuestioned = 'reversal_amount_questioned';
    case SalespersonResponsibilityReview = 'salesperson_responsibility_review';
    case DuplicateOrConflictingRecord = 'duplicate_or_conflicting_record';
    case OperationalReview = 'operational_review';
    case OtherReviewed = 'other_reviewed';

    public function labelKey(): string
    {
        return 'messages.clawback_dispute_reason_'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
