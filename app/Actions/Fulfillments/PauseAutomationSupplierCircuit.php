<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitOpenedSource;
use App\Enums\AutomationCircuitPauseReason;
use App\Enums\AutomationCircuitState;
use App\Models\AutomationSupplierCircuit;
use App\Models\User;
use App\Services\SystemEventService;
use App\Support\Automation\AutomationCircuitGate;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Manually pauses a Wasim supplier capability circuit (C1.3).
 */
final class PauseAutomationSupplierCircuit
{
    public function __construct(
        private readonly EnsureAutomationSupplierCircuits $ensureCircuits,
        private readonly AutomationCircuitGate $gate,
        private readonly SystemEventService $systemEvents,
    ) {}

    public function handle(
        User $actor,
        string $supplierKey,
        AutomationCircuitCapability $capability,
        AutomationCircuitPauseReason $reason,
        ?string $note = null,
    ): AutomationSupplierCircuit {
        $supplierKey = trim($supplierKey) !== '' ? trim($supplierKey) : 'wasim';
        $this->ensureCircuits->handle($supplierKey);

        $safeNote = $note !== null ? mb_substr(trim(strip_tags($note)), 0, 255) : null;

        $circuit = DB::transaction(function () use ($actor, $supplierKey, $capability, $reason, $safeNote): AutomationSupplierCircuit {
            /** @var AutomationSupplierCircuit $circuit */
            $circuit = AutomationSupplierCircuit::query()
                ->where('supplier_key', $supplierKey)
                ->where('capability', $capability->value)
                ->lockForUpdate()
                ->firstOrFail();

            if ($circuit->state === AutomationCircuitState::PausedManual
                && $circuit->reason_code === $reason->value) {
                return $circuit;
            }

            $previous = $circuit->state->value;

            $circuit->state = AutomationCircuitState::PausedManual;
            $circuit->reason_code = $reason->value;
            $circuit->safe_reason_context = $safeNote;
            $circuit->opened_at = now();
            $circuit->opened_by = $actor->id;
            $circuit->opened_source = AutomationCircuitOpenedSource::Manual;
            $circuit->bumpVersion();
            $circuit->save();

            DB::afterCommit(function () use ($circuit, $actor, $previous): void {
                $this->afterPause($circuit->fresh(), $actor, $previous);
            });

            return $circuit->fresh();
        });

        $this->gate->forgetCache($supplierKey, $capability);

        return $circuit;
    }

    private function afterPause(AutomationSupplierCircuit $circuit, User $actor, string $previous): void
    {
        try {
            $this->systemEvents->record(
                'automation.circuit.paused_manual',
                $circuit,
                $actor,
                [
                    'supplier_key' => $circuit->supplier_key,
                    'capability' => $circuit->capability->value,
                    'previous_state' => $previous,
                    'new_state' => $circuit->state->value,
                    'reason_code' => $circuit->reason_code,
                    'opened_source' => 'manual',
                    'actor_id' => $actor->id,
                ],
                'info',
                false,
                $circuit->supplier_key.':'.$circuit->capability->value.':manual:'.$circuit->version,
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
