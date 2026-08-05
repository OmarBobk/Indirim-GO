<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\DTOs\Automation\AutomationHealthCardDTO;
use App\DTOs\Automation\AutomationOperationsDashboardDTO;
use App\DTOs\Automation\AutomationOperationsItemDTO;
use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitState;
use App\Enums\AutomationRunLiveness;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Models\AutomationSupplierCircuit;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\WebsiteSetting;
use App\Services\FulfillmentAutomationService;
use App\Support\Automation\AutomationCircuitGate;
use App\Support\Automation\AutomationOperationsPresenter;
use App\Support\Automation\AutomationRunLivenessClassifier;
use App\Support\Automation\WasimHealthProbeStore;
use App\Support\Automation\WorkerHealthProbe;
use Illuminate\Support\Collection;

/**
 * Builds the admin automation operations dashboard (C1.1 / C1.3).
 */
final class GetAutomationOperationsDashboard
{
    private const LIST_LIMIT = 50;

    private const RECENT_OUTCOMES_LIMIT = 10;

    public function __construct(
        private readonly AutomationRunLivenessClassifier $livenessClassifier,
        private readonly AutomationOperationsPresenter $presenter,
        private readonly WorkerHealthProbe $workerHealthProbe,
        private readonly FulfillmentAutomationService $automationService,
        private readonly WasimHealthProbeStore $wasimProbeStore,
        private readonly AutomationCircuitGate $circuitGate,
    ) {}

    public function handle(): AutomationOperationsDashboardDTO
    {
        $activeRuns = $this->activeRuns();
        $needsReviewRuns = $this->needsReviewRuns();
        $awaitingReconcile = $this->awaitingReconcileFulfillments();

        [$waitingSupplier, $scheduledReconcile, $reconcileExhausted] = $this->bucketAwaitingReconcile($awaitingReconcile);

        $staleActiveRuns = $activeRuns->filter(
            fn (FulfillmentAutomationRun $run): bool => $this->livenessClassifier->classifyActiveWorkerRun($run) === AutomationRunLiveness::Stale,
        )->values();

        $workingNow = $activeRuns
            ->map(fn (FulfillmentAutomationRun $run): AutomationOperationsItemDTO => $this->presenter->presentActiveRun($run))
            ->values()
            ->all();

        $needsAttention = collect()
            ->merge($needsReviewRuns->map(
                fn (FulfillmentAutomationRun $run): AutomationOperationsItemDTO => $this->presenter->presentWithLiveness($run, AutomationRunLiveness::NeedsAttention),
            ))
            ->merge($reconcileExhausted)
            ->merge($staleActiveRuns->map(
                fn (FulfillmentAutomationRun $run): AutomationOperationsItemDTO => $this->presenter->presentWithLiveness($run, AutomationRunLiveness::Stale),
            ))
            ->unique(fn (AutomationOperationsItemDTO $item): string => ($item->runUuid ?? 'f:'.$item->fulfillmentId).':'.$item->kind)
            ->take(self::LIST_LIMIT)
            ->values()
            ->all();

        $needsAttentionCount = count($needsAttention);
        $waitingRecovery = $this->waitingRecoveryItems();

        return new AutomationOperationsDashboardDTO(
            healthCards: $this->healthCards($activeRuns, $waitingSupplier, $scheduledReconcile, $needsAttentionCount),
            workingNow: $workingNow,
            waitingSupplier: $waitingSupplier,
            scheduledReconcile: $scheduledReconcile,
            reconcileExhausted: $reconcileExhausted,
            needsAttention: $needsAttention,
            recentOutcomes: $this->recentOutcomes(),
            waitingRecovery: $waitingRecovery,
        );
    }

    /**
     * @return Collection<int, FulfillmentAutomationRun>
     */
    private function activeRuns(): Collection
    {
        return FulfillmentAutomationRun::query()
            ->active()
            ->with([
                'fulfillment.order:id,order_number',
                'fulfillment.orderItem.product:id,name',
                'fulfillment.orderItem.package:id,name',
            ])
            ->latest('started_at')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, FulfillmentAutomationRun>
     */
    private function needsReviewRuns(): Collection
    {
        return FulfillmentAutomationRun::query()
            ->where('status', FulfillmentAutomationRunStatus::NeedsReview)
            ->with([
                'fulfillment.order:id,order_number',
                'fulfillment.orderItem.product:id,name',
                'fulfillment.orderItem.package:id,name',
            ])
            ->latest('updated_at')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * @return Collection<int, Fulfillment>
     */
    private function awaitingReconcileFulfillments(): Collection
    {
        return Fulfillment::query()
            ->where('status', FulfillmentStatus::Processing)
            ->where('meta->automation->awaiting_wasim_reconcile', true)
            ->with([
                'order:id,order_number',
                'orderItem.product:id,name',
                'orderItem.package:id,name',
                'automationRuns' => fn ($query) => $query->latest('id')->limit(1),
            ])
            ->latest('updated_at')
            ->limit(self::LIST_LIMIT)
            ->get();
    }

    /**
     * @param  Collection<int, Fulfillment>  $fulfillments
     * @return array{0: list<AutomationOperationsItemDTO>, 1: list<AutomationOperationsItemDTO>, 2: list<AutomationOperationsItemDTO>}
     */
    private function bucketAwaitingReconcile(Collection $fulfillments): array
    {
        $waitingSupplier = [];
        $scheduledReconcile = [];
        $reconcileExhausted = [];

        foreach ($fulfillments as $fulfillment) {
            if ($this->automationService->hasActiveRun($fulfillment)) {
                continue;
            }

            if ($this->livenessClassifier->isReconcileExhausted($fulfillment)) {
                $reconcileExhausted[] = $this->presenter->presentAwaitingFulfillment($fulfillment, AutomationRunLiveness::NeedsAttention);

                continue;
            }

            if ($this->livenessClassifier->isScheduledReconcile($fulfillment)) {
                $scheduledReconcile[] = $this->presenter->presentAwaitingFulfillment($fulfillment, AutomationRunLiveness::ScheduledReconcile);

                continue;
            }

            $waitingSupplier[] = $this->presenter->presentAwaitingFulfillment($fulfillment, AutomationRunLiveness::WaitingSupplier);
        }

        return [
            array_slice($waitingSupplier, 0, self::LIST_LIMIT),
            array_slice($scheduledReconcile, 0, self::LIST_LIMIT),
            array_slice($reconcileExhausted, 0, self::LIST_LIMIT),
        ];
    }

    /**
     * @return list<AutomationOperationsItemDTO>
     */
    private function recentOutcomes(): array
    {
        return FulfillmentAutomationRun::query()
            ->terminal()
            ->with([
                'fulfillment.order:id,order_number',
                'fulfillment.orderItem.product:id,name',
                'fulfillment.orderItem.package:id,name',
            ])
            ->latest('finished_at')
            ->limit(self::RECENT_OUTCOMES_LIMIT)
            ->get()
            ->map(fn (FulfillmentAutomationRun $run): AutomationOperationsItemDTO => $this->presenter->presentTerminalRun($run))
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, FulfillmentAutomationRun>  $activeRuns
     * @param  list<AutomationOperationsItemDTO>  $waitingSupplier
     * @param  list<AutomationOperationsItemDTO>  $scheduledReconcile
     * @return list<AutomationHealthCardDTO>
     */
    private function healthCards(
        Collection $activeRuns,
        array $waitingSupplier,
        array $scheduledReconcile,
        int $needsAttentionCount,
    ): array {
        $worker = $this->workerHealthProbe->check();
        $automationEnabled = (bool) config('fulfillment_automation.enabled', false)
            && WebsiteSetting::getAutomationEnabled();

        return [
            new AutomationHealthCardDTO(
                key: 'global',
                state: $automationEnabled ? 'enabled' : 'disabled',
                label: __('messages.automation_health_global'),
                reason: $automationEnabled
                    ? __('messages.automation_health_global_enabled')
                    : __('messages.automation_health_global_disabled'),
                checkedAtIso: now()->toIso8601String(),
            ),
            new AutomationHealthCardDTO(
                key: 'worker',
                state: $worker['status'],
                label: __('messages.automation_health_worker'),
                reason: match ($worker['status']) {
                    'ready' => __('messages.automation_worker_ready'),
                    'degraded' => __('messages.automation_worker_degraded'),
                    default => __('messages.automation_worker_unavailable'),
                },
                checkedAtIso: $worker['checked_at'],
                meta: [
                    'build' => $worker['build'],
                    'instance_id' => $worker['instance_id'],
                    'uptime_seconds' => $worker['uptime_seconds'],
                    'active_count' => $worker['active_count'],
                    'browser_available' => $worker['browser_available'],
                    'max_concurrency' => $worker['configured_max_concurrency'],
                ],
            ),
            new AutomationHealthCardDTO(
                key: 'active_operations',
                state: $activeRuns->isNotEmpty() ? 'active' : 'idle',
                label: __('messages.automation_health_active_ops'),
                checkedAtIso: now()->toIso8601String(),
                meta: [
                    'running' => $activeRuns->count(),
                    'waiting_supplier' => count($waitingSupplier),
                    'scheduled_reconcile' => count($scheduledReconcile),
                ],
            ),
            new AutomationHealthCardDTO(
                key: 'needs_attention',
                state: $needsAttentionCount > 0 ? 'attention' : 'clear',
                label: __('messages.automation_health_needs_attention'),
                checkedAtIso: now()->toIso8601String(),
                meta: ['count' => $needsAttentionCount],
            ),
            $this->sessionHealthCard(),
            $this->driverHealthCard($worker),
            ...$this->circuitHealthCards(),
        ];
    }

    /**
     * @return list<AutomationHealthCardDTO>
     */
    private function circuitHealthCards(): array
    {
        return $this->circuitGate->wasimCircuits()
            ->map(function (AutomationSupplierCircuit $circuit): AutomationHealthCardDTO {
                $state = match ($circuit->state) {
                    AutomationCircuitState::Enabled => 'enabled',
                    AutomationCircuitState::ProbeRequired => 'attention',
                    AutomationCircuitState::PausedAuto, AutomationCircuitState::PausedManual => 'disabled',
                };

                return new AutomationHealthCardDTO(
                    key: 'circuit_'.$circuit->capability->value,
                    state: $state,
                    label: __('messages.automation_circuit_capability_'.$circuit->capability->value),
                    reason: __('messages.automation_circuit_state_'.$circuit->state->value),
                    checkedAtIso: ($circuit->opened_at ?? $circuit->updated_at)?->toIso8601String(),
                    meta: [
                        'reason_code' => $circuit->reason_code,
                        'opened_source' => $circuit->opened_source?->value,
                        'failures' => $circuit->consecutive_failure_count,
                        'last_probe' => $circuit->last_probe_state,
                        'last_healthy_at' => $circuit->last_healthy_at?->toIso8601String(),
                    ],
                );
            })
            ->values()
            ->all();
    }

    /**
     * Queued Wasim purchases blocked by purchase circuit, plus awaiting reconcile blocked by reconcile circuit.
     *
     * @return list<AutomationOperationsItemDTO>
     */
    private function waitingRecoveryItems(): array
    {
        $items = [];

        $purchasePaused = ! $this->circuitGate->isDispatchAllowed('wasim', AutomationCircuitCapability::Purchase);
        $reconcilePaused = ! $this->circuitGate->isDispatchAllowed('wasim', AutomationCircuitCapability::Reconcile);

        if ($purchasePaused) {
            $queued = Fulfillment::query()
                ->where('status', FulfillmentStatus::Queued)
                ->whereNull('claimed_by')
                ->where('provider', 'browser:wasim')
                ->with(['order:id,order_number', 'orderItem.product:id,name', 'orderItem.package:id,name'])
                ->latest('id')
                ->limit(self::LIST_LIMIT)
                ->get();

            foreach ($queued as $fulfillment) {
                $items[] = $this->presenter->presentAwaitingFulfillment($fulfillment, AutomationRunLiveness::NeedsAttention);
            }
        }

        if ($reconcilePaused) {
            $awaiting = Fulfillment::query()
                ->where('status', FulfillmentStatus::Processing)
                ->where('meta->automation->awaiting_wasim_reconcile', true)
                ->with(['order:id,order_number', 'orderItem.product:id,name', 'orderItem.package:id,name'])
                ->latest('updated_at')
                ->limit(self::LIST_LIMIT)
                ->get();

            foreach ($awaiting as $fulfillment) {
                $items[] = $this->presenter->presentAwaitingFulfillment($fulfillment, AutomationRunLiveness::NeedsAttention);
            }
        }

        return collect($items)
            ->unique(fn (AutomationOperationsItemDTO $item): string => 'f:'.$item->fulfillmentId)
            ->take(self::LIST_LIMIT)
            ->values()
            ->all();
    }

    private function sessionHealthCard(): AutomationHealthCardDTO
    {
        $snapshot = $this->wasimProbeStore->get();
        $probe = $snapshot['last_result'] ?? null;

        if ($probe === null) {
            return new AutomationHealthCardDTO(
                key: 'session',
                state: 'unknown',
                label: __('messages.automation_health_session'),
                reason: __('messages.automation_health_session_unknown'),
                checkedAtIso: now()->toIso8601String(),
            );
        }

        $probeState = (string) ($probe['state'] ?? 'unreachable');

        $cardState = match ($probeState) {
            'healthy' => 'healthy',
            'authentication_required' => 'attention',
            'not_configured' => 'unknown',
            'unreachable' => 'unavailable',
            default => 'degraded',
        };

        return new AutomationHealthCardDTO(
            key: 'session',
            state: $cardState,
            label: __('messages.automation_health_session'),
            reason: __('messages.automation_wasim_probe_state_'.$probeState),
            checkedAtIso: is_string($probe['checked_at'] ?? null) ? $probe['checked_at'] : null,
            meta: [
                'session_state' => $probe['session_state'] ?? null,
                'consecutive_failures' => $snapshot['consecutive_failure_count'] ?? 0,
                'last_healthy_at' => $snapshot['last_healthy_at'] ?? null,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $worker
     */
    private function driverHealthCard(array $worker): AutomationHealthCardDTO
    {
        $snapshot = $this->wasimProbeStore->get();
        $probe = $snapshot['last_result'] ?? null;

        if ($probe === null) {
            return new AutomationHealthCardDTO(
                key: 'driver',
                state: 'unknown',
                label: __('messages.automation_health_driver'),
                reason: __('messages.automation_health_session_unknown'),
                checkedAtIso: now()->toIso8601String(),
                meta: [
                    'wasim' => $worker['driver_versions']['wasim'] ?? null,
                    'detected_ui' => null,
                ],
            );
        }

        $probeState = (string) ($probe['state'] ?? 'unreachable');

        $cardState = match ($probeState) {
            'healthy' => 'available',
            'unsupported_ui', 'contract_failed' => 'unavailable',
            default => 'degraded',
        };

        /** @var list<string> $failureCodes */
        $failureCodes = is_array($probe['failure_codes'] ?? null) ? $probe['failure_codes'] : [];

        return new AutomationHealthCardDTO(
            key: 'driver',
            state: $cardState,
            label: __('messages.automation_health_driver'),
            reason: __('messages.automation_wasim_probe_state_'.$probeState),
            checkedAtIso: is_string($probe['checked_at'] ?? null) ? $probe['checked_at'] : null,
            meta: [
                'wasim' => $probe['driver_version'] ?? ($worker['driver_versions']['wasim'] ?? null),
                'detected_ui' => $probe['detected_ui_version'] ?? null,
                'purchase_contract' => $probe['purchase_contract_version'] ?? null,
                'orders_contract' => $probe['orders_contract_version'] ?? null,
                'failure_codes' => $failureCodes === [] ? null : implode(', ', array_slice($failureCodes, 0, 5)),
            ],
        );
    }
}
