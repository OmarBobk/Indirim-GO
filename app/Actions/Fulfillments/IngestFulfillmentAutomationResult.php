<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Actions\Orders\RefundOrderItem;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentLogLevel;
use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Services\FulfillmentAutomationService;
use App\Services\SystemEventService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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

            if (! in_array($outcome, ['success', 'failed', 'needs_review', 'submitted', 'pending_reconcile'], true)) {
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

            if ($outcome === 'submitted') {
                return $this->handleSubmitted($lockedRun, $lockedFulfillment, $payload, $resultPayload, $logExcerpt);
            }

            if ($outcome === 'pending_reconcile') {
                return $this->handlePendingReconcile($lockedRun, $lockedFulfillment, $payload, $resultPayload, $logExcerpt);
            }

            if ($outcome === 'success') {
                return $this->handleSuccess($lockedRun, $lockedFulfillment, $payload, $resultPayload, $logExcerpt);
            }

            return $this->handleFailure($lockedRun, $lockedFulfillment, $payload, $resultPayload, $logExcerpt, $outcome);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $resultPayload
     */
    private function handleSubmitted(
        FulfillmentAutomationRun $lockedRun,
        Fulfillment $lockedFulfillment,
        array $payload,
        ?array $resultPayload,
        mixed $logExcerpt,
    ): FulfillmentAutomationRun {
        $externalOrderId = isset($payload['external_order_id'])
            ? (string) $payload['external_order_id']
            : null;

        $deliveredPayload = is_array($resultPayload) ? $resultPayload : [];
        $deliveredPayload['supplier_order_id'] = $externalOrderId;
        $deliveredPayload['automation_run_uuid'] = $lockedRun->uuid;
        $deliveredPayload['automation'] = true;
        $deliveredPayload['phase'] = 'purchase';

        $this->applySupplierEntryPriceToOrderItem($lockedFulfillment, $deliveredPayload);

        $fulfillmentMeta = $lockedFulfillment->meta ?? [];
        $fulfillmentMeta['automation'] = array_merge($fulfillmentMeta['automation'] ?? [], [
            'awaiting_wasim_reconcile' => true,
            'supplier_order_id' => $externalOrderId,
            'purchase_run_uuid' => $lockedRun->uuid,
            'submitted_at' => now()->toIso8601String(),
            'reconcile_attempts' => 0,
            'last_run_uuid' => $lockedRun->uuid,
        ]);

        $lockedRun->fill([
            'status' => FulfillmentAutomationRunStatus::Succeeded,
            'external_order_id' => $externalOrderId,
            'result_payload' => $deliveredPayload,
            'log_excerpt' => is_array($logExcerpt) ? $logExcerpt : null,
            'finished_at' => now(),
            'callback_received_at' => now(),
        ])->save();

        $lockedFulfillment->update(['meta' => $fulfillmentMeta]);

        app(AppendFulfillmentLog::class)->handle(
            $lockedFulfillment,
            FulfillmentLogLevel::Info,
            'Wasim order submitted; awaiting supplier completion',
            [
                'action' => 'automation_submitted',
                'run_uuid' => $lockedRun->uuid,
                'external_order_id' => $externalOrderId,
            ],
        );

        $this->recordTerminalEvent($lockedRun, $lockedFulfillment, 'fulfillment.automation.submitted');

        app(ScheduleWasimOrderReconcile::class)->handle($lockedFulfillment->refresh(), 0);

        return $lockedRun->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $resultPayload
     */
    private function handlePendingReconcile(
        FulfillmentAutomationRun $lockedRun,
        Fulfillment $lockedFulfillment,
        array $payload,
        ?array $resultPayload,
        mixed $logExcerpt,
    ): FulfillmentAutomationRun {
        $externalOrderId = isset($payload['external_order_id'])
            ? (string) $payload['external_order_id']
            : data_get($lockedFulfillment->meta, 'automation.supplier_order_id');

        $deliveredPayload = is_array($resultPayload) ? $resultPayload : [];
        $deliveredPayload['supplier_order_id'] = $externalOrderId;
        $deliveredPayload['automation_run_uuid'] = $lockedRun->uuid;
        $deliveredPayload['automation'] = true;
        $deliveredPayload['phase'] = 'reconcile';

        $fulfillmentMeta = $lockedFulfillment->meta ?? [];
        $attempts = (int) data_get($fulfillmentMeta, 'automation.reconcile_attempts', 0) + 1;
        $fulfillmentMeta['automation'] = array_merge($fulfillmentMeta['automation'] ?? [], [
            'awaiting_wasim_reconcile' => true,
            'reconcile_attempts' => $attempts,
            'last_reconcile_run_uuid' => $lockedRun->uuid,
            'last_reconcile_at' => now()->toIso8601String(),
            'last_run_uuid' => $lockedRun->uuid,
        ]);

        $lockedRun->fill([
            'status' => FulfillmentAutomationRunStatus::Succeeded,
            'external_order_id' => is_string($externalOrderId) ? $externalOrderId : null,
            'result_payload' => $deliveredPayload,
            'log_excerpt' => is_array($logExcerpt) ? $logExcerpt : null,
            'finished_at' => now(),
            'callback_received_at' => now(),
        ])->save();

        $lockedFulfillment->update(['meta' => $fulfillmentMeta]);

        app(AppendFulfillmentLog::class)->handle(
            $lockedFulfillment,
            FulfillmentLogLevel::Info,
            'Wasim order still processing on supplier site',
            [
                'action' => 'automation_reconcile_pending',
                'run_uuid' => $lockedRun->uuid,
                'reconcile_attempts' => $attempts,
            ],
        );

        $this->recordTerminalEvent($lockedRun, $lockedFulfillment, 'fulfillment.automation.reconcile_pending');

        if ($this->automationService->shouldScheduleReconcile($lockedFulfillment->refresh(), $attempts)) {
            app(ScheduleWasimOrderReconcile::class)->handle($lockedFulfillment->refresh(), $attempts);
        } else {
            $this->markReconcileExhausted($lockedRun, $lockedFulfillment);
        }

        return $lockedRun->refresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $resultPayload
     */
    private function handleSuccess(
        FulfillmentAutomationRun $lockedRun,
        Fulfillment $lockedFulfillment,
        array $payload,
        ?array $resultPayload,
        mixed $logExcerpt,
    ): FulfillmentAutomationRun {
        $externalOrderId = isset($payload['external_order_id'])
            ? (string) $payload['external_order_id']
            : null;

        $deliveredPayload = is_array($resultPayload) ? $resultPayload : [];
        $deliveredPayload['supplier_order_id'] = $externalOrderId;
        $deliveredPayload['automation_run_uuid'] = $lockedRun->uuid;
        $deliveredPayload['automation'] = true;

        $this->applySupplierEntryPriceToOrderItem($lockedFulfillment, $deliveredPayload);

        $fulfillmentMeta = $lockedFulfillment->meta ?? [];
        $fulfillmentMeta['automation'] = array_merge($fulfillmentMeta['automation'] ?? [], [
            'awaiting_wasim_reconcile' => false,
            'supplier_order_id' => $externalOrderId,
            'last_run_uuid' => $lockedRun->uuid,
            'completed_at' => now()->toIso8601String(),
        ]);

        $lockedRun->fill([
            'status' => FulfillmentAutomationRunStatus::Succeeded,
            'external_order_id' => $externalOrderId,
            'result_payload' => $deliveredPayload,
            'log_excerpt' => is_array($logExcerpt) ? $logExcerpt : null,
            'finished_at' => now(),
            'callback_received_at' => now(),
        ])->save();

        $lockedFulfillment->update(['meta' => $fulfillmentMeta]);

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

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>|null  $resultPayload
     */
    private function handleFailure(
        FulfillmentAutomationRun $lockedRun,
        Fulfillment $lockedFulfillment,
        array $payload,
        ?array $resultPayload,
        mixed $logExcerpt,
        string $outcome,
    ): FulfillmentAutomationRun {
        $errorCode = (string) ($payload['error_code'] ?? 'automation_failed');
        $errorMessage = (string) ($payload['message'] ?? $payload['error_message'] ?? 'Automation failed');
        $runStatus = $outcome === 'needs_review'
            ? FulfillmentAutomationRunStatus::NeedsReview
            : FulfillmentAutomationRunStatus::Failed;

        $fulfillmentMeta = $lockedFulfillment->meta ?? [];
        $fulfillmentMeta['automation'] = array_merge($fulfillmentMeta['automation'] ?? [], [
            'awaiting_wasim_reconcile' => false,
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

        if ($outcome === 'failed' && in_array($errorCode, ['supplier_order_rejected', 'supplier_order_cancelled'], true)) {
            $this->queueSupplierRejectionRefund($lockedFulfillment);
        }

        $eventType = $outcome === 'needs_review'
            ? 'fulfillment.automation.needs_review'
            : 'fulfillment.automation.failed';

        $this->recordTerminalEvent($lockedRun, $lockedFulfillment, $eventType);

        return $lockedRun->refresh();
    }

    private function markReconcileExhausted(
        FulfillmentAutomationRun $lockedRun,
        Fulfillment $lockedFulfillment,
    ): void {
        $fulfillmentMeta = $lockedFulfillment->meta ?? [];
        $fulfillmentMeta['automation'] = array_merge($fulfillmentMeta['automation'] ?? [], [
            'requires_review' => true,
            'awaiting_wasim_reconcile' => true,
        ]);

        $lockedFulfillment->update(['meta' => $fulfillmentMeta]);

        app(AppendFulfillmentLog::class)->handle(
            $lockedFulfillment,
            FulfillmentLogLevel::Warn,
            'Wasim reconcile attempts exhausted; needs manual review',
            [
                'action' => 'automation_reconcile_exhausted',
                'run_uuid' => $lockedRun->uuid,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $deliveredPayload
     */
    private function applySupplierEntryPriceToOrderItem(Fulfillment $fulfillment, array $deliveredPayload): void
    {
        $supplierEntryPrice = data_get($deliveredPayload, 'supplier_entry_price');

        if (! is_numeric($supplierEntryPrice)) {
            return;
        }

        OrderItem::query()
            ->whereKey($fulfillment->order_item_id)
            ->update(['entry_price' => (float) $supplierEntryPrice]);
    }

    private function queueSupplierRejectionRefund(Fulfillment $fulfillment): void
    {
        $fulfillmentId = $fulfillment->id;
        $orderUserId = Order::query()->whereKey($fulfillment->order_id)->value('user_id');

        if ($orderUserId === null) {
            return;
        }

        DB::afterCommit(function () use ($fulfillmentId, $orderUserId): void {
            $fulfillment = Fulfillment::query()->find($fulfillmentId);

            if ($fulfillment === null || $fulfillment->status !== FulfillmentStatus::Failed) {
                return;
            }

            try {
                app(RefundOrderItem::class)->handle(
                    $fulfillment,
                    (int) $orderUserId,
                    'Supplier rejected order (automation)',
                );
            } catch (ValidationException) {
                app(AppendFulfillmentLog::class)->handle(
                    $fulfillment,
                    FulfillmentLogLevel::Warn,
                    'Automatic refund after supplier rejection was not allowed',
                    ['action' => 'automation_refund_skipped'],
                );
            }
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
