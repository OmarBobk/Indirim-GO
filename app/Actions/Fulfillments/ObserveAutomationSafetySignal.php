<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitOpenedSource;
use App\Enums\AutomationCircuitState;
use App\Models\AutomationSupplierCircuit;
use App\Models\User;
use App\Notifications\AutomationCircuitPausedNotification;
use App\Notifications\AutomationCircuitProbeRequiredNotification;
use App\Services\NotificationRecipientService;
use App\Services\SystemEventService;
use App\Support\Automation\AutomationCircuitGate;
use App\Support\Automation\AutomationCircuitPolicy;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Observes a typed automation safety signal and may open/update a Wasim circuit (C1.3).
 *
 * Laravel owns policy. Worker/browser only supply allowlisted failure codes.
 */
final class ObserveAutomationSafetySignal
{
    public function __construct(
        private readonly AutomationCircuitPolicy $policy,
        private readonly AutomationCircuitGate $gate,
        private readonly EnsureAutomationSupplierCircuits $ensureCircuits,
        private readonly SystemEventService $systemEvents,
        private readonly NotificationRecipientService $recipients,
    ) {}

    /**
     * @param  array{
     *     supplier_key?: string,
     *     failure_code: string,
     *     source_type: string,
     *     source_key: string,
     *     capability_hint?: string|null,
     *     occurred_at?: \DateTimeInterface|string|null,
     * }  $input
     */
    public function handle(array $input): ?AutomationSupplierCircuit
    {
        $failureCode = trim((string) ($input['failure_code'] ?? ''));
        $rule = $this->policy->ruleFor($failureCode);

        if ($rule === null || $rule['no_circuit'] === true) {
            return null;
        }

        $supplierKey = trim((string) ($input['supplier_key'] ?? 'wasim'));
        if ($supplierKey === '') {
            $supplierKey = 'wasim';
        }

        $capability = $rule['capability'];
        $hint = $input['capability_hint'] ?? null;
        if (is_string($hint) && $hint !== '') {
            $fromHint = AutomationCircuitCapability::tryFrom($hint);
            if ($fromHint !== null) {
                $capability = $fromHint;
            }
        }

        $sourceType = trim((string) ($input['source_type'] ?? 'unknown'));
        $sourceKey = trim((string) ($input['source_key'] ?? ''));
        if ($sourceKey === '') {
            return null;
        }

        $signalKey = hash('sha256', implode('|', [$supplierKey, $capability->value, $failureCode, $sourceType, $sourceKey]));
        $occurredAt = $this->parseOccurredAt($input['occurred_at'] ?? null);

        $this->ensureCircuits->handle($supplierKey);

        $result = DB::transaction(function () use (
            $supplierKey,
            $capability,
            $failureCode,
            $rule,
            $signalKey,
            $occurredAt,
            $sourceType,
        ): array {
            /** @var AutomationSupplierCircuit $circuit */
            $circuit = AutomationSupplierCircuit::query()
                ->where('supplier_key', $supplierKey)
                ->where('capability', $capability->value)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var list<string> $recent */
            $recent = is_array($circuit->recent_signal_keys) ? $circuit->recent_signal_keys : [];

            if (in_array($signalKey, $recent, true)) {
                return ['circuit' => $circuit, 'changed' => false, 'event' => null];
            }

            $threshold = $this->policy->thresholdFor($capability);
            $windowMinutes = $threshold['window_minutes'];
            $thresholdCount = $threshold['count'];

            $windowStart = $circuit->failure_window_started_at;
            if ($windowStart === null || $windowStart->lt($occurredAt->copy()->subMinutes($windowMinutes))) {
                $windowStart = $occurredAt;
                $circuit->consecutive_failure_count = 0;
                $recent = [];
            }

            $circuit->failure_window_started_at = $windowStart;
            $circuit->last_failure_at = $occurredAt;
            $circuit->consecutive_failure_count = ((int) $circuit->consecutive_failure_count) + 1;
            $recent[] = $signalKey;
            $circuit->recent_signal_keys = array_slice($recent, -20);

            $shouldPause = $rule['immediate'] === true
                || ($rule['threshold'] === true && $circuit->consecutive_failure_count >= $thresholdCount);

            $previous = $circuit->state;
            $event = null;
            $changed = false;

            if ($shouldPause && $circuit->state === AutomationCircuitState::Enabled) {
                $circuit->state = AutomationCircuitState::PausedAuto;
                $circuit->reason_code = $failureCode;
                $circuit->safe_reason_context = $rule['scope'];
                $circuit->opened_at = $occurredAt;
                $circuit->opened_by = null;
                $circuit->opened_source = $rule['immediate']
                    ? AutomationCircuitOpenedSource::Auto
                    : AutomationCircuitOpenedSource::Threshold;
                $circuit->bumpVersion();
                $changed = true;
                $event = 'automation.circuit.paused_auto';
            } elseif ($shouldPause && $circuit->state === AutomationCircuitState::ProbeRequired) {
                // New supplier-wide failure while awaiting resume → re-open auto pause.
                $circuit->state = AutomationCircuitState::PausedAuto;
                $circuit->reason_code = $failureCode;
                $circuit->safe_reason_context = $rule['scope'];
                $circuit->opened_at = $occurredAt;
                $circuit->opened_source = AutomationCircuitOpenedSource::Auto;
                $circuit->bumpVersion();
                $changed = true;
                $event = 'automation.circuit.paused_auto';
            } elseif ($circuit->state === AutomationCircuitState::PausedManual) {
                // Keep manual pause; refresh reason context only.
                $circuit->reason_code = $circuit->reason_code ?: $failureCode;
            }

            $circuit->save();

            return [
                'circuit' => $circuit->fresh(),
                'changed' => $changed,
                'event' => $event,
                'previous' => $previous->value,
                'source_type' => $sourceType,
            ];
        });

        $this->gate->forgetCache($supplierKey, $capability);

        if (($result['changed'] ?? false) === true && is_string($result['event'] ?? null)) {
            $circuit = $result['circuit'];
            assert($circuit instanceof AutomationSupplierCircuit);

            DB::afterCommit(function () use ($circuit, $result): void {
                $this->notifyPaused($circuit, (string) $result['event'], (string) ($result['previous'] ?? 'enabled'));
            });
        }

        return $result['circuit'] ?? null;
    }

    /**
     * Apply a healthy probe outcome to auto-paused circuits that require probe-gated resume.
     *
     * @param  array<string, mixed>  $probeResult
     */
    public function handleHealthyProbe(string $supplierKey, array $probeResult): void
    {
        $this->ensureCircuits->handle($supplierKey);
        $checkedAt = $this->parseOccurredAt($probeResult['checked_at'] ?? null);
        $probeState = (string) ($probeResult['state'] ?? 'healthy');

        foreach (AutomationCircuitCapability::cases() as $capability) {
            DB::transaction(function () use ($supplierKey, $capability, $checkedAt, $probeState, $probeResult): void {
                /** @var AutomationSupplierCircuit $circuit */
                $circuit = AutomationSupplierCircuit::query()
                    ->where('supplier_key', $supplierKey)
                    ->where('capability', $capability->value)
                    ->lockForUpdate()
                    ->firstOrFail();

                $circuit->last_probe_at = $checkedAt;
                $circuit->last_probe_state = $probeState;

                if ($probeState === 'healthy') {
                    $circuit->last_healthy_at = $checkedAt;
                    $circuit->consecutive_failure_count = 0;
                    $circuit->failure_window_started_at = null;
                    $circuit->recent_signal_keys = [];
                }

                $changed = false;
                $event = null;
                $previous = $circuit->state;

                if (
                    $probeState === 'healthy'
                    && $circuit->state === AutomationCircuitState::PausedAuto
                ) {
                    $circuit->state = AutomationCircuitState::ProbeRequired;
                    $circuit->bumpVersion();
                    $changed = true;
                    $event = 'automation.circuit.probe_required';
                }

                // Map probe failure codes into pause when not already paused manually.
                if ($probeState !== 'healthy' && $circuit->state === AutomationCircuitState::Enabled) {
                    $codes = is_array($probeResult['failure_codes'] ?? null) ? $probeResult['failure_codes'] : [];
                    foreach ($codes as $code) {
                        if (! is_string($code)) {
                            continue;
                        }
                        $rule = $this->policy->ruleFor($code);
                        if ($rule === null || $rule['no_circuit'] || $rule['capability'] !== $capability) {
                            continue;
                        }
                        if ($rule['immediate'] || $probeState === 'unsupported_ui' || $probeState === 'maintenance') {
                            $circuit->state = AutomationCircuitState::PausedAuto;
                            $circuit->reason_code = $code;
                            $circuit->safe_reason_context = $rule['scope'];
                            $circuit->opened_at = $checkedAt;
                            $circuit->opened_source = AutomationCircuitOpenedSource::Probe;
                            $circuit->bumpVersion();
                            $changed = true;
                            $event = 'automation.circuit.paused_auto';
                            break;
                        }
                    }
                }

                $circuit->save();
                $this->gate->forgetCache($supplierKey, $capability);

                if ($changed && is_string($event)) {
                    DB::afterCommit(function () use ($circuit, $event, $previous): void {
                        if ($event === 'automation.circuit.probe_required') {
                            $this->notifyProbeRequired($circuit->fresh(), $previous->value);
                        } else {
                            $this->notifyPaused($circuit->fresh(), $event, $previous->value);
                        }
                    });
                }
            });
        }
    }

    private function parseOccurredAt(mixed $value): Carbon
    {
        if ($value instanceof Carbon) {
            return $value;
        }

        if ($value instanceof \DateTimeInterface) {
            return Carbon::parse($value->format('c'));
        }

        if (is_string($value) && $value !== '') {
            try {
                return Carbon::parse($value);
            } catch (Throwable) {
                // fall through
            }
        }

        return Carbon::now();
    }

    private function notifyPaused(AutomationSupplierCircuit $circuit, string $eventType, string $previous): void
    {
        try {
            $this->systemEvents->record(
                $eventType,
                $circuit,
                null,
                [
                    'supplier_key' => $circuit->supplier_key,
                    'capability' => $circuit->capability->value,
                    'previous_state' => $previous,
                    'new_state' => $circuit->state->value,
                    'reason_code' => $circuit->reason_code,
                    'opened_source' => $circuit->opened_source?->value,
                    'consecutive_failure_count' => $circuit->consecutive_failure_count,
                ],
                'warning',
                false,
                $circuit->supplier_key.':'.$circuit->capability->value.':'.$circuit->version,
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            app(BroadcastAutomationRunChanged::class)->handle(null, 'circuit_changed', $circuit->state->value);
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $notification = AutomationCircuitPausedNotification::fromCircuit($circuit);
            foreach ($this->recipients->adminUsers() as $admin) {
                if ($admin instanceof User) {
                    $admin->notify($notification);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function notifyProbeRequired(AutomationSupplierCircuit $circuit, string $previous): void
    {
        try {
            $this->systemEvents->record(
                'automation.circuit.probe_required',
                $circuit,
                null,
                [
                    'supplier_key' => $circuit->supplier_key,
                    'capability' => $circuit->capability->value,
                    'previous_state' => $previous,
                    'new_state' => $circuit->state->value,
                    'reason_code' => $circuit->reason_code,
                    'last_probe_state' => $circuit->last_probe_state,
                ],
                'info',
                false,
                $circuit->supplier_key.':'.$circuit->capability->value.':probe:'.$circuit->version,
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            app(BroadcastAutomationRunChanged::class)->handle(null, 'circuit_changed', $circuit->state->value);
        } catch (Throwable $exception) {
            report($exception);
        }

        try {
            $notification = AutomationCircuitProbeRequiredNotification::fromCircuit($circuit);
            foreach ($this->recipients->adminUsers() as $admin) {
                if ($admin instanceof User) {
                    $admin->notify($notification);
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }
}
