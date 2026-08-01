<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CommissionClawbackDisputeResolution;
use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;
use Illuminate\Support\Facades\Route;

final class CommissionClawbackDisputeResolvedNotification extends BaseNotification
{
    public static function fromDecision(
        CommissionClawback $clawback,
        CommissionClawbackDecision $decision,
    ): self {
        $resolution = CommissionClawbackDisputeResolution::tryFrom((string) $decision->reason_code);
        $messageKey = match ($resolution) {
            CommissionClawbackDisputeResolution::Rejected,
            CommissionClawbackDisputeResolution::Withdrawn => 'notifications.commission_clawback_dispute_rejected_message',
            CommissionClawbackDisputeResolution::AcceptedAsWaiver => 'notifications.commission_clawback_dispute_accepted_waiver_message',
            CommissionClawbackDisputeResolution::AcceptedAsCorrection => 'notifications.commission_clawback_dispute_accepted_correction_message',
            default => 'notifications.commission_clawback_dispute_resolved_message',
        };

        $summary = is_string($decision->safe_resolution_summary) && trim($decision->safe_resolution_summary) !== ''
            ? trim($decision->safe_resolution_summary)
            : null;

        $url = Route::has('wallet.earnings.index')
            ? route('wallet.earnings.index')
            : null;

        return new self(
            sourceType: CommissionClawbackDecision::class,
            sourceId: (int) $decision->id,
            titleKey: 'notifications.commission_clawback_dispute_resolved_title',
            messageKey: $messageKey,
            messageParams: [
                'clawback_ref' => (string) $clawback->public_ref,
                'summary' => $summary ?? '',
            ],
            url: $url,
        );
    }
}
