<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\DTOs\Automation\AutomationProgressPayloadDTO;
use App\Enums\FulfillmentAutomationProgressStep;
use App\Models\FulfillmentAutomationRun;
use App\Models\FulfillmentAutomationRunEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Ingests a heartbeat/step-change progress callback from the automation
 * worker (C1.1).
 *
 * Progress callbacks are purely observational: they never mutate
 * fulfillment status or financials. Only the run's progress bookkeeping
 * (sequence, heartbeat timestamp, current step, snapshot) and its event
 * trail are updated here.
 */
final class IngestFulfillmentAutomationProgress
{
    /**
     * @return array{status: string, applied: bool, reason: ?string}
     */
    public function handle(FulfillmentAutomationRun $run, AutomationProgressPayloadDTO $payload): array
    {
        $step = FulfillmentAutomationProgressStep::tryFrom($payload->step);

        if ($step === null) {
            return $this->ignored('invalid_step');
        }

        if (! $step->isCompatibleWithPhase($payload->phase)) {
            return $this->ignored('phase_mismatch');
        }

        $skewSeconds = (int) config('fulfillment_automation.progress.emitted_at_skew_seconds', 300);
        $driftSeconds = abs(Carbon::now()->getTimestamp() - $payload->emittedAt->getTimestamp());

        if ($driftSeconds > $skewSeconds) {
            return $this->ignored('emitted_at_skew');
        }

        return DB::transaction(function () use ($run, $payload, $step): array {
            $lockedRun = FulfillmentAutomationRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRun->isTerminal()) {
                return $this->ignored('terminal');
            }

            if ($payload->progressSequence < $lockedRun->progress_sequence) {
                return $this->ignored('out_of_order');
            }

            if ($payload->progressSequence === $lockedRun->progress_sequence) {
                return $this->ignored('duplicate');
            }

            $previousStep = $lockedRun->currentProgressStep();
            $stepChanged = $previousStep !== $step->value;
            $emittedAt = $payload->emittedAt;

            $lockedRun->fill([
                'progress_sequence' => $payload->progressSequence,
                'last_heartbeat_at' => $emittedAt,
                'current_step_started_at' => $stepChanged
                    ? $emittedAt
                    : ($lockedRun->current_step_started_at ?? $emittedAt),
                'progress_snapshot' => [
                    'sequence' => $payload->progressSequence,
                    'phase' => $payload->phase,
                    'step' => $step->value,
                    'safe_message_code' => $payload->safeMessageCode,
                    'safe_params' => $payload->safeParams,
                    'emitted_at' => $emittedAt->toIso8601String(),
                    'step_started_at' => ($stepChanged
                        ? $emittedAt
                        : ($lockedRun->current_step_started_at ?? $emittedAt))->toIso8601String(),
                    'last_heartbeat_at' => $emittedAt->toIso8601String(),
                    'worker_instance_id' => $payload->workerInstanceId,
                    'worker_build' => $payload->workerBuild,
                    'driver_name' => $payload->driverName,
                    'driver_version' => $payload->driverVersion,
                    'detected_ui_version' => $payload->detectedUiVersion,
                    'page_contract_version' => $payload->pageContractVersion,
                    'session_alias' => $payload->sessionAlias,
                    'heartbeat' => $payload->heartbeat,
                ],
            ])->save();

            if ($stepChanged) {
                $this->recordEvent($lockedRun, $payload, $step, $emittedAt);
                $this->pruneEvents($lockedRun);

                app(BroadcastAutomationRunChanged::class)->handle(
                    $lockedRun->uuid,
                    'run_progress_changed',
                    $lockedRun->status->value,
                );
            }

            return [
                'status' => 'accepted',
                'applied' => true,
                'reason' => $stepChanged ? 'step_changed' : 'heartbeat',
            ];
        });
    }

    private function recordEvent(
        FulfillmentAutomationRun $run,
        AutomationProgressPayloadDTO $payload,
        FulfillmentAutomationProgressStep $step,
        Carbon $occurredAt,
    ): void {
        FulfillmentAutomationRunEvent::query()->create([
            'run_id' => $run->id,
            'sequence' => $payload->progressSequence,
            'phase' => $payload->phase,
            'step' => $step->value,
            'safe_message_code' => $payload->safeMessageCode,
            'safe_params' => $payload->safeParams,
            'occurred_at' => $occurredAt,
            'worker_instance_id' => $payload->workerInstanceId,
            'worker_build' => $payload->workerBuild,
            'created_at' => now(),
        ]);
    }

    private function pruneEvents(FulfillmentAutomationRun $run): void
    {
        $limit = max(1, (int) config('fulfillment_automation.progress.events_per_run_limit', 100));

        $keepFromSequence = FulfillmentAutomationRunEvent::query()
            ->where('run_id', $run->id)
            ->orderByDesc('sequence')
            ->skip($limit - 1)
            ->take(1)
            ->value('sequence');

        if ($keepFromSequence === null) {
            return;
        }

        FulfillmentAutomationRunEvent::query()
            ->where('run_id', $run->id)
            ->where('sequence', '<', $keepFromSequence)
            ->delete();
    }

    /**
     * @return array{status: string, applied: bool, reason: string}
     */
    private function ignored(string $reason): array
    {
        return ['status' => 'ignored', 'applied' => false, 'reason' => $reason];
    }
}
