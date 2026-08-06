<?php

declare(strict_types=1);

namespace App\Actions\SupplierPrices;

use App\Enums\AutomationCircuitCapability;
use App\Models\SupplierPriceScan;
use App\Services\SupplierPriceScanService;
use App\Support\Automation\AutomationCircuitGate;
use Illuminate\Support\Collection;
use RuntimeException;

class StartSupplierPriceScan
{
    public function __construct(
        private readonly SupplierPriceScanService $scanService,
        private readonly DispatchSupplierPriceScan $dispatchScan,
        private readonly AutomationCircuitGate $circuitGate,
    ) {}

    public function handle(?int $packageId = null, ?int $limit = null, string $triggeredBy = 'command'): SupplierPriceScan
    {
        if (! $this->scanService->isEnabled()) {
            throw new RuntimeException('Supplier price scan is disabled or fulfillment automation is not configured.');
        }

        if (! $this->circuitGate->isDispatchAllowed('wasim', AutomationCircuitCapability::PriceScan)) {
            throw new RuntimeException(__('messages.automation_circuit_price_scan_paused'));
        }

        if (! $this->scanService->wasimCredentialsConfigured()) {
            throw new RuntimeException('Wasim automation credentials are not configured.');
        }

        if ($this->scanService->hasRunningScan()) {
            throw new RuntimeException('A Wasim price scan is already running.');
        }

        $products = $this->scanService->scannableProducts($packageId, $limit);

        if ($products->isEmpty()) {
            throw new RuntimeException('No Wasim-linked products with product_api found.');
        }

        $scan = $this->scanService->createScan($products, triggeredBy: $triggeredBy);

        return $this->dispatchScan->handle($scan);
    }

    /**
     * @return Collection<int, \App\Models\Product>
     */
    public function scannableProducts(?int $packageId = null, ?int $limit = null): Collection
    {
        return $this->scanService->scannableProducts($packageId, $limit);
    }
}
