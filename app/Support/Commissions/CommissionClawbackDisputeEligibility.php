<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\DTOs\Admin\CommissionClawbackDisputeDecisionDTO;
use App\Enums\CommissionClawbackStatus;
use App\Models\CommissionClawback;

/**
 * Server-only dispute open eligibility (M7.2.3).
 */
final class CommissionClawbackDisputeEligibility
{
    public function __construct(
        private readonly CommissionClawbackDisputeState $disputeState = new CommissionClawbackDisputeState,
    ) {}

    public function decideOpen(CommissionClawback $clawback): CommissionClawbackDisputeDecisionDTO
    {
        $status = $clawback->status instanceof CommissionClawbackStatus
            ? $clawback->status
            : CommissionClawbackStatus::tryFrom((string) $clawback->status);

        if ($status === CommissionClawbackStatus::Waived) {
            return $this->denied($clawback, 'messages.clawback_dispute_already_final', 'already_final');
        }

        $active = $this->disputeState->activeOpenDispute($clawback);
        if ($active !== null) {
            return new CommissionClawbackDisputeDecisionDTO(
                allowed: false,
                mode: 'active_exists',
                safeDenialKey: 'messages.clawback_dispute_already_open',
                status: $status?->value ?? (string) $clawback->status,
                isPosted: $status === CommissionClawbackStatus::Posted
                    || $clawback->reversal_wallet_transaction_id !== null,
                activeDisputeId: (int) $active->id,
                activeDisputeRef: (string) $active->public_ref,
            );
        }

        if ($status === CommissionClawbackStatus::Processing
            && ! (new CommissionClawbackRetryEligibility)->isStaleProcessing($clawback)
        ) {
            return $this->denied($clawback, 'messages.clawback_dispute_still_processing', 'still_processing');
        }

        $isPosted = $status === CommissionClawbackStatus::Posted
            || $clawback->reversal_wallet_transaction_id !== null;

        return new CommissionClawbackDisputeDecisionDTO(
            allowed: true,
            mode: $isPosted ? 'posted' : 'unposted',
            safeDenialKey: '',
            status: $status?->value ?? (string) $clawback->status,
            isPosted: $isPosted,
        );
    }

    public function decideResolve(CommissionClawback $clawback): CommissionClawbackDisputeDecisionDTO
    {
        $active = $this->disputeState->activeOpenDispute($clawback);
        if ($active === null) {
            return $this->denied($clawback, 'messages.clawback_dispute_none_open', 'none_open');
        }

        $status = $clawback->status instanceof CommissionClawbackStatus
            ? $clawback->status
            : CommissionClawbackStatus::tryFrom((string) $clawback->status);

        return new CommissionClawbackDisputeDecisionDTO(
            allowed: true,
            mode: 'resolve',
            safeDenialKey: '',
            status: $status?->value ?? (string) $clawback->status,
            isPosted: $status === CommissionClawbackStatus::Posted
                || $clawback->reversal_wallet_transaction_id !== null,
            activeDisputeId: (int) $active->id,
            activeDisputeRef: (string) $active->public_ref,
        );
    }

    private function denied(
        CommissionClawback $clawback,
        string $key,
        string $mode,
    ): CommissionClawbackDisputeDecisionDTO {
        return new CommissionClawbackDisputeDecisionDTO(
            allowed: false,
            mode: $mode,
            safeDenialKey: $key,
            status: $clawback->status instanceof CommissionClawbackStatus
                ? $clawback->status->value
                : (string) $clawback->status,
            isPosted: $clawback->status === CommissionClawbackStatus::Posted
                || $clawback->reversal_wallet_transaction_id !== null,
        );
    }
}
