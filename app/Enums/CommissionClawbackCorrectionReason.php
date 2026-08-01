<?php

declare(strict_types=1);

namespace App\Enums;

enum CommissionClawbackCorrectionReason: string
{
    case WrongCommission = 'wrong_commission';
    case WrongRefundRelationship = 'wrong_refund_relationship';
    case ExcessiveReversal = 'excessive_reversal';
    case DuplicateLogicalRecovery = 'duplicate_logical_recovery';
    case InvalidPolicyApplication = 'invalid_policy_application';
    case ReversalSourceInvalid = 'reversal_source_invalid';
    case RefundReversed = 'refund_reversed';
    case SoftwareErrorConfirmed = 'software_error_confirmed';
    case OtherConfirmedError = 'other_confirmed_error';

    public function labelKey(): string
    {
        return 'messages.clawback_correction_reason_'.$this->value;
    }

    public function salespersonSafeLabelKey(): string
    {
        return 'messages.clawback_correction_reason_safe_'.$this->value;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
