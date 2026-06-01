<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentLogLevel;
use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\OrderItem;
use App\Services\FulfillmentAutomationService;
use App\Services\SystemEventService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DispatchFulfillmentAutomationRun
{
    public function __construct(
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    public function handle(FulfillmentAutomationRun $run, Fulfillment $fulfillment): FulfillmentAutomationRun
    {
        return DB::transaction(function () use ($run, $fulfillment): FulfillmentAutomationRun {
            $lockedRun = FulfillmentAutomationRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRun->status !== FulfillmentAutomationRunStatus::Reserved) {
                return $lockedRun;
            }

            $lockedFulfillment = Fulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $payload = $this->automationService->buildWorkerPayload($lockedRun, $lockedFulfillment);
            $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
            $timestamp = time();
            $signature = $this->automationService->signPayload($rawBody, $timestamp);

            $workerUrl = rtrim((string) config('fulfillment_automation.worker_url'), '/');
            $timeout = (int) config('fulfillment_automation.timeouts.dispatch_seconds', 15);

            try {
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-Automation-Timestamp' => (string) $timestamp,
                        'X-Automation-Signature' => $signature,
                    ])
                    ->withBody($rawBody, 'application/json')
                    ->post($workerUrl.'/v1/runs');

                if (! $response->successful()) {
                    throw new RuntimeException('Worker rejected run: HTTP '.$response->status());
                }
            } catch (Throwable $exception) {
                Log::error('Fulfillment automation dispatch failed', [
                    'run_uuid' => $lockedRun->uuid,
                    'fulfillment_id' => $lockedFulfillment->id,
                    'error' => $exception->getMessage(),
                ]);

                $lockedRun->fill([
                    'status' => FulfillmentAutomationRunStatus::Failed,
                    'finished_at' => now(),
                    'error_code' => 'dispatch_failed',
                    'error_message' => $exception->getMessage(),
                ])->save();

                app(AppendFulfillmentLog::class)->handle(
                    $lockedFulfillment,
                    FulfillmentLogLevel::Error,
                    'Automation dispatch failed',
                    [
                        'action' => 'automation_dispatch_failed',
                        'run_uuid' => $lockedRun->uuid,
                        'error' => $exception->getMessage(),
                    ],
                );

                if ((bool) config('fulfillment_automation.dispatch.revert_to_queued_on_dispatch_failure', true)) {
                    $lockedFulfillment->fill([
                        'status' => FulfillmentStatus::Queued,
                        'processed_at' => null,
                        'last_error' => $exception->getMessage(),
                    ])->save();

                    $orderItem = OrderItem::query()
                        ->whereKey($lockedFulfillment->order_item_id)
                        ->lockForUpdate()
                        ->first();

                    if ($orderItem !== null) {
                        $fulfillments = Fulfillment::query()
                            ->where('order_item_id', $orderItem->id)
                            ->lockForUpdate()
                            ->get();
                        $orderItem->syncStatusFromFulfillments($fulfillments);
                    }
                }

                throw $exception;
            }

            $lockedRun->fill([
                'status' => FulfillmentAutomationRunStatus::Running,
                'dispatched_at' => now(),
                'started_at' => now(),
            ])->save();

            app(AppendFulfillmentLog::class)->handle(
                $lockedFulfillment,
                FulfillmentLogLevel::Info,
                'Automation run dispatched',
                [
                    'action' => 'automation_dispatched',
                    'run_uuid' => $lockedRun->uuid,
                ],
            );

            $runId = $lockedRun->id;
            DB::afterCommit(function () use ($runId, $lockedFulfillment): void {
                $run = FulfillmentAutomationRun::query()->find($runId);
                if ($run === null) {
                    return;
                }

                app(SystemEventService::class)->record(
                    'fulfillment.automation.dispatched',
                    $run,
                    null,
                    [
                        'fulfillment_id' => $lockedFulfillment->id,
                        'run_uuid' => $run->uuid,
                        'supplier_key' => $run->supplier_key,
                    ],
                    'info',
                    false,
                    'fulfillment.automation.dispatched:'.$run->uuid,
                );
            });

            activity()
                ->inLog('fulfillment')
                ->event('fulfillment.automation.dispatched')
                ->performedOn($lockedFulfillment)
                ->withProperties([
                    'fulfillment_id' => $lockedFulfillment->id,
                    'run_uuid' => $lockedRun->uuid,
                    'supplier_key' => $lockedRun->supplier_key,
                ])
                ->log('Automation run dispatched');

            return $lockedRun->refresh();
        });
    }
}
