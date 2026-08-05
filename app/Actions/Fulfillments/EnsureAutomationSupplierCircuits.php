<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitState;
use App\Models\AutomationSupplierCircuit;

/**
 * Ensures durable Wasim circuit rows exist (purchase / reconcile / price_scan).
 */
final class EnsureAutomationSupplierCircuits
{
    public function handle(string $supplierKey = 'wasim'): void
    {
        foreach (AutomationCircuitCapability::cases() as $capability) {
            AutomationSupplierCircuit::query()->firstOrCreate(
                [
                    'supplier_key' => $supplierKey,
                    'capability' => $capability->value,
                ],
                [
                    'state' => AutomationCircuitState::Enabled,
                    'consecutive_failure_count' => 0,
                    'recent_signal_keys' => [],
                    'version' => 1,
                ],
            );
        }
    }
}
