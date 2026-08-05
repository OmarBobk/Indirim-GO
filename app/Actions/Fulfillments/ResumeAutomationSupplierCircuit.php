<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitPauseReason;
use App\Enums\AutomationCircuitState;
use App\Enums\WasimHealthProbeState;
use App\Models\AutomationSupplierCircuit;
use App\Models\User;
use App\Services\SystemEventService;
use App\Support\Automation\AutomationCircuitGate;
use App\Support\Automation\AutomationCircuitPolicy;
use App\Support\Automation\WasimHealthProbeStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Resumes a Wasim supplier capability circuit after policy checks (C1.3).
 */
final class ResumeAutomationSupplierCircuit
{
    public function __construct(
        private readonly EnsureAutomationSupplierCircuits $ensureCircuits,
        private readonly AutomationCircuitGate $gate,
        private readonly AutomationCircuitPolicy $policy,
        private readonly WasimHealthProbeStore $probeStore,
        private readonly SystemEventService $systemEvents,
    ) {}

    public function handle(
        User $actor,
        string $supplierKey,
        AutomationCircuitCapability $capability,
        bool $confirmed,
        ?string $note = null,
    ): AutomationSupplierCircuit {
        if (! $confirmed) {
            throw ValidationException::withMessages([
                'confirmed' => [__('messages.automation_circuit_resume_confirmation_required')],
            ]);
        }

        $supplierKey = trim($supplierKey) !== '' ? trim($supplierKey) : 'wasim';
        $this->ensureCircuits->handle($supplierKey);
        $safeNote = $note !== null ? mb_substr(trim(strip_tags($note)), 0, 255) : null;

        $circuit = DB::transaction(function () use ($actor, $supplierKey, $capability, $safeNote): AutomationSupplierCircuit {
            /** @var AutomationSupplierCircuit $circuit */
            $circuit = AutomationSupplierCircuit::query()
                ->where('supplier_key', $supplierKey)
                ->where('capability', $capability->value)
                ->lockForUpdate()
                ->firstOrFail();

            if ($circuit->state === AutomationCircuitState::Enabled) {
                return $circuit;
            }

            $this->assertResumeAllowed($circuit);

            $previous = $circuit->state->value;

            $circuit->state = AutomationCircuitState::Enabled;
            $circuit->resumed_at = now();
            $circuit->resumed_by = $actor->id;
            $circuit->resume_source = 'manual';
            $circuit->consecutive_failure_count = 0;
            $circuit->failure_window_started_at = null;
            $circuit->recent_signal_keys = [];
            if ($safeNote !== null && $safeNote !== '') {
                $circuit->safe_reason_context = $safeNote;
            }
            $circuit->bumpVersion();
            $circuit->save();

            DB::afterCommit(function () use ($circuit, $actor, $previous): void {
                $this->afterResume($circuit->fresh(), $actor, $previous);
            });

            return $circuit->fresh();
        });

        $this->gate->forgetCache($supplierKey, $capability);

        return $circuit;
    }

    private function assertResumeAllowed(AutomationSupplierCircuit $circuit): void
    {
        if ($circuit->state === AutomationCircuitState::PausedManual) {
            $reason = AutomationCircuitPauseReason::tryFrom((string) $circuit->reason_code);
            if ($reason !== null && $reason->requiresProbeBeforeResume()) {
                $this->assertFreshHealthyProbe($circuit);
            }

            return;
        }

        // paused_auto and probe_required always require a fresh healthy supported probe.
        $this->assertFreshHealthyProbe($circuit);

        if ($circuit->state === AutomationCircuitState::PausedAuto) {
            throw ValidationException::withMessages([
                'circuit' => [__('messages.automation_circuit_resume_probe_required_first')],
            ]);
        }
    }

    private function assertFreshHealthyProbe(AutomationSupplierCircuit $circuit): void
    {
        $snapshot = $this->probeStore->get();
        $result = $snapshot['last_result'] ?? null;

        if (! is_array($result)) {
            throw ValidationException::withMessages([
                'probe' => [__('messages.automation_circuit_resume_probe_missing')],
            ]);
        }

        $state = (string) ($result['state'] ?? '');
        if ($state !== WasimHealthProbeState::Healthy->value) {
            throw ValidationException::withMessages([
                'probe' => [__('messages.automation_circuit_resume_probe_not_healthy')],
            ]);
        }

        $checkedAt = (string) ($result['checked_at'] ?? '');
        try {
            $fresh = Carbon::parse($checkedAt)->diffInSeconds(now(), true) <= $this->policy->probeFreshnessSeconds();
        } catch (Throwable) {
            $fresh = false;
        }

        if (! $fresh) {
            throw ValidationException::withMessages([
                'probe' => [__('messages.automation_circuit_resume_probe_stale')],
            ]);
        }

        $uiVersion = $result['detected_ui_version'] ?? null;
        if (is_string($uiVersion) && $uiVersion !== '' && ! in_array($uiVersion, $this->policy->supportedUiVersions(), true)) {
            throw ValidationException::withMessages([
                'probe' => [__('messages.automation_circuit_resume_ui_unsupported')],
            ]);
        }

        if ($circuit->capability === AutomationCircuitCapability::Purchase) {
            $purchaseState = (string) ($result['purchase_contract_state'] ?? '');
            if (! in_array($purchaseState, ['healthy', 'skipped', 'not_configured', ''], true)
                && $purchaseState !== 'price_readable') {
                // Allow not_configured / skipped; block explicit contract_failed.
                if ($purchaseState === 'contract_failed') {
                    throw ValidationException::withMessages([
                        'probe' => [__('messages.automation_circuit_resume_contract_failed')],
                    ]);
                }
            }
        }

        if ($circuit->capability === AutomationCircuitCapability::Reconcile) {
            $reconcileState = (string) ($result['reconcile_contract_state'] ?? '');
            if ($reconcileState === 'contract_failed') {
                throw ValidationException::withMessages([
                    'probe' => [__('messages.automation_circuit_resume_contract_failed')],
                ]);
            }
        }
    }

    private function afterResume(AutomationSupplierCircuit $circuit, User $actor, string $previous): void
    {
        try {
            $this->systemEvents->record(
                'automation.circuit.resumed',
                $circuit,
                $actor,
                [
                    'supplier_key' => $circuit->supplier_key,
                    'capability' => $circuit->capability->value,
                    'previous_state' => $previous,
                    'new_state' => $circuit->state->value,
                    'actor_id' => $actor->id,
                    'last_probe_state' => $circuit->last_probe_state,
                ],
                'info',
                false,
                $circuit->supplier_key.':'.$circuit->capability->value.':resume:'.$circuit->version,
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            app(BroadcastAutomationRunChanged::class)->handle(null, 'circuit_changed', $circuit->state->value);
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
