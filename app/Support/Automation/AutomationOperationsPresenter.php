<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\DTOs\Automation\AutomationOperationsItemDTO;
use App\Enums\AutomationRunLiveness;
use App\Enums\FulfillmentAutomationProgressStep;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Services\FulfillmentAutomationService;

/**
 * Builds safe, display-ready rows for the automation operations dashboard (C1.1).
 */
final class AutomationOperationsPresenter
{
    public function __construct(
        private readonly AutomationRunLivenessClassifier $livenessClassifier,
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    public function presentActiveRun(FulfillmentAutomationRun $run): AutomationOperationsItemDTO
    {
        $liveness = $this->livenessClassifier->classifyActiveWorkerRun($run);

        return $this->fromRun(
            kind: 'active_run',
            run: $run,
            fulfillment: $run->fulfillment,
            liveness: $liveness,
            presentation: 'working_now',
            actionRequired: $liveness === AutomationRunLiveness::Stale,
        );
    }

    public function presentWithLiveness(FulfillmentAutomationRun $run, AutomationRunLiveness $liveness): AutomationOperationsItemDTO
    {
        return $this->fromRun(
            kind: 'needs_review',
            run: $run,
            fulfillment: $run->fulfillment,
            liveness: $liveness,
            presentation: $run->status === FulfillmentAutomationRunStatus::NeedsReview ? 'needs_review' : $liveness->value,
            actionRequired: true,
        );
    }

    public function presentTerminalRun(FulfillmentAutomationRun $run): AutomationOperationsItemDTO
    {
        $presentation = $this->honestSucceededPresentation($run, $run->fulfillment);

        return $this->fromRun(
            kind: 'recent_outcome',
            run: $run,
            fulfillment: $run->fulfillment,
            liveness: AutomationRunLiveness::Unknown,
            presentation: $presentation,
            actionRequired: $run->status === FulfillmentAutomationRunStatus::NeedsReview,
        );
    }

    public function presentAwaitingFulfillment(Fulfillment $fulfillment, AutomationRunLiveness $liveness): AutomationOperationsItemDTO
    {
        /** @var FulfillmentAutomationRun|null $run */
        $run = $fulfillment->automationRuns->first();

        $presentation = match ($liveness) {
            AutomationRunLiveness::ScheduledReconcile => 'scheduled_reconcile',
            AutomationRunLiveness::NeedsAttention => 'reconcile_exhausted',
            default => 'supplier_accepted_awaiting_reconcile',
        };

        $stepLabel = match ($liveness) {
            AutomationRunLiveness::ScheduledReconcile => __('messages.automation_scheduled_reconciliation'),
            AutomationRunLiveness::NeedsAttention => __('messages.automation_reconciliation_exhausted'),
            default => __('messages.automation_waiting_for_supplier'),
        };

        return $this->fromRun(
            kind: $presentation,
            run: $run,
            fulfillment: $fulfillment,
            liveness: $liveness,
            presentation: $presentation,
            actionRequired: $liveness === AutomationRunLiveness::NeedsAttention,
            stepLabelOverride: $stepLabel,
            forcePhase: 'reconcile',
        );
    }

    private function safeString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function honestSucceededPresentation(FulfillmentAutomationRun $run, ?Fulfillment $fulfillment): string
    {
        if (
            $run->status === FulfillmentAutomationRunStatus::Succeeded
            && $fulfillment !== null
            && (bool) data_get($fulfillment->meta, 'automation.awaiting_wasim_reconcile', false)
        ) {
            return 'supplier_accepted_awaiting_reconcile';
        }

        return $run->status->value;
    }

    private function fromRun(
        string $kind,
        ?FulfillmentAutomationRun $run,
        ?Fulfillment $fulfillment,
        AutomationRunLiveness $liveness,
        string $presentation,
        bool $actionRequired,
        ?string $stepLabelOverride = null,
        ?string $forcePhase = null,
    ): AutomationOperationsItemDTO {
        $fulfillment?->loadMissing(['order', 'orderItem.product', 'orderItem.package']);

        $snapshot = $run?->progress_snapshot ?? [];
        $resultPayload = is_array($run?->result_payload) ? $run->result_payload : [];
        $step = $run?->currentProgressStep();
        $phase = $forcePhase
            ?? (is_string(data_get($snapshot, 'phase')) ? (string) data_get($snapshot, 'phase') : null)
            ?? ($run !== null && $this->automationService->isReconcileRun($run) ? 'reconcile' : 'purchase');

        if ($stepLabelOverride !== null) {
            $stepLabel = $stepLabelOverride;
        } elseif ($step !== null) {
            $enum = FulfillmentAutomationProgressStep::tryFrom($step);
            $stepLabel = $enum !== null ? __($enum->labelKey()) : __('messages.automation_progress_unavailable');
        } else {
            $stepLabel = $run !== null && $run->isActive()
                ? __('messages.automation_progress_unavailable')
                : __('messages.automation_no_active_step');
        }

        return new AutomationOperationsItemDTO(
            kind: $kind,
            runUuid: $run?->uuid,
            fulfillmentId: (int) ($fulfillment?->id ?? $run?->fulfillment_id ?? 0),
            fulfillmentReference: 'F-'.(int) ($fulfillment?->id ?? $run?->fulfillment_id ?? 0),
            orderNumber: $fulfillment?->order?->order_number,
            packageName: $fulfillment?->orderItem?->package?->name ?? $fulfillment?->orderItem?->name,
            productName: $fulfillment?->orderItem?->product?->name,
            supplierKey: (string) ($run?->supplier_key ?? $fulfillment?->browserSupplierKey() ?? 'unknown'),
            phase: $phase,
            runStatus: $run?->status->value,
            step: $step,
            stepLabel: $stepLabel,
            presentation: $presentation,
            liveness: $liveness->value,
            actionRequired: $actionRequired,
            runStartedAtIso: ($run?->started_at ?? $run?->dispatched_at)?->toIso8601String(),
            stepStartedAtIso: $run?->current_step_started_at?->toIso8601String()
                ?? (is_string(data_get($snapshot, 'step_started_at')) ? (string) data_get($snapshot, 'step_started_at') : null),
            lastHeartbeatAtIso: $run?->last_heartbeat_at?->toIso8601String(),
            workerBuild: is_string(data_get($snapshot, 'worker_build')) ? (string) data_get($snapshot, 'worker_build') : null,
            workerInstanceId: is_string(data_get($snapshot, 'worker_instance_id')) ? (string) data_get($snapshot, 'worker_instance_id') : null,
            driverVersion: is_string(data_get($snapshot, 'driver_version')) ? (string) data_get($snapshot, 'driver_version') : null,
            detectedUiVersion: $this->safeString(data_get($snapshot, 'detected_ui_version') ?? data_get($resultPayload, 'detected_ui_version')),
            pageContractVersion: $this->safeString(
                data_get($snapshot, 'page_contract_version')
                    ?? data_get($snapshot, 'purchase_contract_version')
                    ?? data_get($resultPayload, 'page_contract_version')
                    ?? data_get($resultPayload, 'purchase_contract_version'),
            ),
            adapter: $this->safeString(data_get($snapshot, 'adapter') ?? data_get($resultPayload, 'adapter')),
            supplierOrderId: $run?->external_order_id
                ?? (is_string(data_get($fulfillment?->meta, 'automation.supplier_order_id'))
                    ? (string) data_get($fulfillment?->meta, 'automation.supplier_order_id')
                    : null),
            nextReconcileAtIso: is_string(data_get($fulfillment?->meta, 'automation.next_reconcile_at'))
                ? (string) data_get($fulfillment?->meta, 'automation.next_reconcile_at')
                : null,
            attempt: (int) ($run?->attempt ?? data_get($fulfillment?->meta, 'automation.reconcile_attempts', 0)),
            detailRunUuid: $run?->uuid,
        );
    }
}
