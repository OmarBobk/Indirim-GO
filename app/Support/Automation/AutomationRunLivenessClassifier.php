<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Enums\AutomationRunLiveness;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Classifies the liveness of an in-flight automation run or an
 * awaiting-supplier fulfillment.
 *
 * Prefers heartbeat-based classification (from C1.1 progress callbacks)
 * since it reflects the worker's actual step cadence per phase. Falls back
 * to the legacy started_at/dispatched_at age check for runs from worker
 * builds that do not emit progress callbacks yet.
 */
final class AutomationRunLivenessClassifier
{
    public function classifyActiveWorkerRun(FulfillmentAutomationRun $run, ?Carbon $now = null): AutomationRunLiveness
    {
        $now ??= Carbon::now();

        if ($run->last_heartbeat_at !== null) {
            return $this->classifyByHeartbeat($run, $now);
        }

        return $this->classifyByLegacyAge($run, $now);
    }

    public function isWaitingSupplier(Fulfillment $fulfillment): bool
    {
        return (bool) data_get($fulfillment->meta, 'automation.awaiting_wasim_reconcile', false);
    }

    public function isScheduledReconcile(Fulfillment $fulfillment, ?Carbon $now = null): bool
    {
        $now ??= Carbon::now();
        $nextReconcileAt = data_get($fulfillment->meta, 'automation.next_reconcile_at');

        if (! is_string($nextReconcileAt) || $nextReconcileAt === '') {
            return false;
        }

        try {
            return Carbon::parse($nextReconcileAt)->greaterThan($now);
        } catch (Throwable) {
            return false;
        }
    }

    public function isReconcileExhausted(Fulfillment $fulfillment): bool
    {
        return $this->isWaitingSupplier($fulfillment)
            && (bool) data_get($fulfillment->meta, 'automation.requires_review', false);
    }

    private function classifyByHeartbeat(FulfillmentAutomationRun $run, Carbon $now): AutomationRunLiveness
    {
        $phase = $this->resolvePhase($run);
        $secondsSinceHeartbeat = abs($now->getTimestamp() - $run->last_heartbeat_at->getTimestamp());

        $slowSeconds = (int) config("fulfillment_automation.liveness.{$phase}_slow_seconds", 180);
        $staleSeconds = (int) config("fulfillment_automation.liveness.{$phase}_stale_seconds", 480);

        if ($secondsSinceHeartbeat >= $staleSeconds) {
            return AutomationRunLiveness::Stale;
        }

        if ($secondsSinceHeartbeat >= $slowSeconds) {
            return AutomationRunLiveness::Slow;
        }

        return AutomationRunLiveness::Healthy;
    }

    private function classifyByLegacyAge(FulfillmentAutomationRun $run, Carbon $now): AutomationRunLiveness
    {
        $anchor = $run->started_at ?? $run->dispatched_at ?? $run->created_at;

        if ($anchor === null) {
            return AutomationRunLiveness::Unknown;
        }

        $minutes = abs($now->diffInMinutes($anchor));
        $staleMinutes = (int) config('fulfillment_automation.liveness.legacy_fallback_stale_minutes', 30);

        return $minutes >= $staleMinutes ? AutomationRunLiveness::Stale : AutomationRunLiveness::Healthy;
    }

    private function resolvePhase(FulfillmentAutomationRun $run): string
    {
        $phase = data_get($run->progress_snapshot, 'phase');

        return $phase === 'reconcile' ? 'reconcile' : 'purchase';
    }
}
