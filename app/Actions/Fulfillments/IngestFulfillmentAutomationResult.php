<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentLogLevel;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Services\FulfillmentAutomationService;
use App\Services\SystemEventService;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IngestFulfillmentAutomationResult
{
    public function __construct(
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(FulfillmentAutomationRun $run, array $payload): FulfillmentAutomationRun
    {
        return DB::transaction(function () use ($run, $payload): FulfillmentAutomationRun {
            $lockedRun = FulfillmentAutomationRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRun->isTerminal()) {
                return $lockedRun;
            }

            if (! in_array($lockedRun->status, [
                FulfillmentAutomationRunStatus::Dispatched,
                FulfillmentAutomationRunStatus::Running,
            ], true)) {
                return $lockedRun;
            }

            $outcome = (string) ($payload['outcome'] ?? '');

            if (! in_array($outcome, ['success', 'failed', 'needs_review'], true)) {
                throw new InvalidArgumentException('Invalid automation outcome.');
            }

            $lockedFulfillment = Fulfillment::query()
                ->whereKey($lockedRun->fulfillment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $logExcerpt = $payload['log_excerpt'] ?? null;
            $resultPayload = $payload['delivered_payload'] ?? $payload['result_payload'] ?? null;
            $resultPayload = $this->automationService->enrichResultPayload(
                $lockedFulfillment,
                is_array($resultPayload) ? $resultPayload : null,
            );

            if ($outcome === 'success') {
                $externalOrderId = isset($payload['external_order_id'])
                    ? (string) $payload['external_order_id']
                    : null;

                $deliveredPayload = is_array($resultPayload) ? $resultPayload : [];
                $deliveredPayload['supplier_order_id'] = $externalOrderId;
                $deliveredPayload['automation_run_uuid'] = $lockedRun->uuid;
                $deliveredPayload['automation'] = true;

                $lockedRun->fill([
                    'status' => FulfillmentAutomationRunStatus::Succeeded,
                    'external_order_id' => $externalOrderId,
                    'result_payload' => $deliveredPayload,
                    'log_excerpt' => is_array($logExcerpt) ? $logExcerpt : null,
                    'finished_at' => now(),
                    'callback_received_at' => now(),
                ])->save();

                app(AppendFulfillmentLog::class)->handle(
                    $lockedFulfillment,
                    FulfillmentLogLevel::Info,
                    'Automation succeeded',
                    [
                        'action' => 'automation_succeeded',
                        'run_uuid' => $lockedRun->uuid,
                        'external_order_id' => $externalOrderId,
                    ],
                );

                app(CompleteFulfillment::class)->handle(
                    $lockedFulfillment,
                    $deliveredPayload,
                    'automation',
                    null,
                );

                $this->recordTerminalEvent($lockedRun, $lockedFulfillment, 'fulfillment.automation.succeeded');

                return $lockedRun->refresh();
            }

            $errorCode = (string) ($payload['error_code'] ?? 'automation_failed');
            $errorMessage = (string) ($payload['message'] ?? $payload['error_message'] ?? 'Automation failed');
            $runStatus = $outcome === 'needs_review'
                ? FulfillmentAutomationRunStatus::NeedsReview
                : FulfillmentAutomationRunStatus::Failed;

            $fulfillmentMeta = $lockedFulfillment->meta ?? [];
            $fulfillmentMeta['automation'] = array_merge($fulfillmentMeta['automation'] ?? [], [
                'requires_review' => $outcome === 'needs_review',
                'last_run_uuid' => $lockedRun->uuid,
                'last_error_code' => $errorCode,
            ]);

            $lockedRun->fill([
                'status' => $runStatus,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'result_payload' => is_array($resultPayload) ? $resultPayload : null,
                'log_excerpt' => is_array($logExcerpt) ? $logExcerpt : null,
                'finished_at' => now(),
                'callback_received_at' => now(),
            ])->save();

            $lockedFulfillment->update(['meta' => $fulfillmentMeta]);

            app(AppendFulfillmentLog::class)->handle(
                $lockedFulfillment,
                FulfillmentLogLevel::Error,
                $outcome === 'needs_review' ? 'Automation needs review' : 'Automation failed',
                [
                    'action' => $outcome === 'needs_review' ? 'automation_needs_review' : 'automation_failed',
                    'run_uuid' => $lockedRun->uuid,
                    'error_code' => $errorCode,
                    'message' => $errorMessage,
                ],
            );

            app(FailFulfillment::class)->handle(
                $lockedFulfillment,
                $errorMessage,
                'automation',
                null,
            );

            $eventType = $outcome === 'needs_review'
                ? 'fulfillment.automation.needs_review'
                : 'fulfillment.automation.failed';

            $this->recordTerminalEvent($lockedRun, $lockedFulfillment, $eventType);

            return $lockedRun->refresh();
        });
    }

    private function recordTerminalEvent(
        FulfillmentAutomationRun $run,
        Fulfillment $fulfillment,
        string $eventType,
    ): void {
        $runId = $run->id;
        $fulfillmentId = $fulfillment->id;

        DB::afterCommit(function () use ($runId, $fulfillmentId, $eventType): void {
            $run = FulfillmentAutomationRun::query()->find($runId);
            if ($run === null) {
                return;
            }

            app(BroadcastAutomationRunChanged::class)->handle(
                $run->uuid,
                str_replace('fulfillment.automation.', '', $eventType),
                $run->status->value,
            );

            app(SystemEventService::class)->record(
                $eventType,
                $run,
                null,
                [
                    'fulfillment_id' => $fulfillmentId,
                    'run_uuid' => $run->uuid,
                    'supplier_key' => $run->supplier_key,
                    'status' => $run->status->value,
                ],
                'info',
                false,
                $eventType.':'.$run->uuid,
            );
        });

        activity()
            ->inLog('fulfillment')
            ->event($eventType)
            ->performedOn($fulfillment)
            ->withProperties([
                'fulfillment_id' => $fulfillment->id,
                'run_uuid' => $run->uuid,
                'supplier_key' => $run->supplier_key,
            ])
            ->log(str_replace('fulfillment.automation.', 'Automation ', $eventType));
    }
}
