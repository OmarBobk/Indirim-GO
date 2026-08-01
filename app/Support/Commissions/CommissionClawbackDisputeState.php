<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Enums\CommissionClawbackDecisionStatus;
use App\Enums\CommissionClawbackDecisionType;
use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;

/**
 * Active dispute derived from immutable decision rows (M7.2.3).
 * No stored CommissionClawbackStatus::Disputed.
 */
final class CommissionClawbackDisputeState
{
    public function activeOpenDispute(CommissionClawback $clawback): ?CommissionClawbackDecision
    {
        $opens = CommissionClawbackDecision::query()
            ->where('commission_clawback_id', $clawback->id)
            ->where('type', CommissionClawbackDecisionType::DisputeOpened)
            ->where('status', CommissionClawbackDecisionStatus::Open)
            ->orderByDesc('id')
            ->get();

        foreach ($opens as $open) {
            $resolved = CommissionClawbackDecision::query()
                ->where('commission_clawback_id', $clawback->id)
                ->where('type', CommissionClawbackDecisionType::DisputeResolved)
                ->where('parent_decision_id', $open->id)
                ->exists();

            if (! $resolved) {
                return $open;
            }
        }

        return null;
    }

    public function hasActiveDispute(CommissionClawback $clawback): bool
    {
        return $this->activeOpenDispute($clawback) !== null;
    }

    /**
     * @return list<CommissionClawbackDecision>
     */
    public function disputeTimeline(CommissionClawback $clawback): array
    {
        return CommissionClawbackDecision::query()
            ->where('commission_clawback_id', $clawback->id)
            ->whereIn('type', [
                CommissionClawbackDecisionType::DisputeOpened,
                CommissionClawbackDecisionType::DisputeResolved,
            ])
            ->orderBy('decided_at')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
