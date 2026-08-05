<?php

declare(strict_types=1);

namespace App\Support\Automation;

use App\Actions\Fulfillments\EnsureAutomationSupplierCircuits;
use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitState;
use App\Models\AutomationSupplierCircuit;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * Read helpers for Wasim circuit gating (C1.3).
 */
final class AutomationCircuitGate
{
    private const CACHE_TTL_SECONDS = 5;

    public function __construct(
        private readonly EnsureAutomationSupplierCircuits $ensureCircuits,
    ) {}

    public function isDispatchAllowed(string $supplierKey, AutomationCircuitCapability $capability): bool
    {
        return ! $this->circuit($supplierKey, $capability)->blocksDispatch();
    }

    public function circuit(string $supplierKey, AutomationCircuitCapability $capability): AutomationSupplierCircuit
    {
        $this->ensureCircuits->handle($supplierKey);

        $cacheKey = "fulfillment-automation:circuit.{$supplierKey}.{$capability->value}";

        /** @var AutomationSupplierCircuit $circuit */
        $circuit = Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($supplierKey, $capability): AutomationSupplierCircuit {
            return AutomationSupplierCircuit::query()
                ->where('supplier_key', $supplierKey)
                ->where('capability', $capability->value)
                ->firstOrFail();
        });

        return $circuit;
    }

    public function forgetCache(string $supplierKey, AutomationCircuitCapability $capability): void
    {
        Cache::forget("fulfillment-automation:circuit.{$supplierKey}.{$capability->value}");
    }

    /**
     * @return Collection<int, AutomationSupplierCircuit>
     */
    public function wasimCircuits(): Collection
    {
        $this->ensureCircuits->handle('wasim');

        return AutomationSupplierCircuit::query()
            ->where('supplier_key', 'wasim')
            ->orderBy('capability')
            ->get();
    }

    public function anyPaused(string $supplierKey = 'wasim'): bool
    {
        return $this->wasimCircuits()
            ->contains(fn (AutomationSupplierCircuit $circuit): bool => $circuit->state !== AutomationCircuitState::Enabled);
    }
}
