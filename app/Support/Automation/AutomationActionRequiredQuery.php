<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationRunLiveness;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use Illuminate\Support\Carbon;

/**
 * Bounded action-required automation query helpers (C1.1 / C1.3).
 * Counts needs_review runs + stale active runs + reconcile-exhausted
 * fulfillments + auto-paused / probe-required Wasim circuits without
 * double-counting routine manual pauses as emergencies.
 */
final class AutomationActionRequiredQuery
{
    public static function needsReviewRunsCount(): int
    {
        return FulfillmentAutomationRun::query()
            ->where('status', FulfillmentAutomationRunStatus::NeedsReview)
            ->count();
    }

    public static function staleActiveRunsCount(?Carbon $now = null): int
    {
        $classifier = new AutomationRunLivenessClassifier;

        return FulfillmentAutomationRun::query()
            ->active()
            ->get()
            ->filter(fn (FulfillmentAutomationRun $run): bool => $classifier->classifyActiveWorkerRun($run, $now) === AutomationRunLiveness::Stale)
            ->count();
    }

    public static function reconcileExhaustedCount(): int
    {
        return Fulfillment::query()
            ->where('status', FulfillmentStatus::Processing)
            ->where('meta->automation->awaiting_wasim_reconcile', true)
            ->where('meta->automation->requires_review', true)
            ->count();
    }

    public static function pausedSafetyCircuitsCount(): int
    {
        return \App\Models\AutomationSupplierCircuit::query()
            ->where('supplier_key', 'wasim')
            ->whereIn('state', [
                \App\Enums\AutomationCircuitState::PausedAuto->value,
                \App\Enums\AutomationCircuitState::ProbeRequired->value,
            ])
            ->count();
    }

    public static function total(): int
    {
        return self::needsReviewRunsCount()
            + self::staleActiveRunsCount()
            + self::reconcileExhaustedCount()
            + self::pausedSafetyCircuitsCount();
    }
}
