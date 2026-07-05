<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\ProductAmountMode;
use App\Enums\SupplierPriceFlagReason;
use App\Enums\SupplierPriceScanItemStatus;
use App\Enums\SupplierPriceScanStatus;
use App\Models\Fulfillment;
use App\Models\Product;
use App\Models\SupplierPriceScan;
use App\Models\SupplierPriceScanItem;
use App\Models\WebsiteSetting;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SupplierPriceScanService
{
    public function __construct(
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    public function isEnabled(): bool
    {
        return (bool) config('fulfillment_automation.price_scan.enabled', true)
            && $this->automationService->isEnabled();
    }

    public function hasRunningScan(string $supplierKey = 'wasim'): bool
    {
        return SupplierPriceScan::query()
            ->where('supplier_key', $supplierKey)
            ->whereIn('status', [
                SupplierPriceScanStatus::Pending->value,
                SupplierPriceScanStatus::Running->value,
            ])
            ->exists();
    }

    /**
     * @return Builder<Product>
     */
    public function scannableProductsQuery(?int $packageId = null, ?int $limit = null): Builder
    {
        $query = Product::query()
            ->whereNotNull('product_api')
            ->where('product_api', '!=', '')
            ->whereHas('package', function (Builder $packageQuery): void {
                $packageQuery->where('fulfillment_provider', 'browser:wasim');
            })
            ->with('package:id,name,fulfillment_provider')
            ->orderBy('package_id')
            ->orderBy('order')
            ->orderBy('id');

        if ($packageId !== null) {
            $query->where('package_id', $packageId);
        }

        if ($limit !== null && $limit > 0) {
            $query->limit($limit);
        }

        return $query->with('package:id,name');
    }

    /**
     * @return Collection<int, Product>
     */
    public function scannableProducts(?int $packageId = null, ?int $limit = null): Collection
    {
        return $this->scannableProductsQuery($packageId, $limit)->get();
    }

    /**
     * @param  Collection<int, Product>  $products
     */
    public function createScan(Collection $products, string $supplierKey = 'wasim', string $triggeredBy = 'command'): SupplierPriceScan
    {
        $referenceQuantity = (int) config('fulfillment_automation.price_scan.custom_reference_quantity', 1000);

        $scan = SupplierPriceScan::query()->create([
            'uuid' => (string) Str::uuid(),
            'supplier_key' => $supplierKey,
            'status' => SupplierPriceScanStatus::Pending,
            'products_total' => $products->count(),
            'triggered_by' => $triggeredBy,
            'meta' => [
                'custom_reference_quantity' => $referenceQuantity,
            ],
        ]);

        foreach ($products as $product) {
            SupplierPriceScanItem::query()->create([
                'supplier_price_scan_id' => $scan->id,
                'product_id' => $product->id,
                'product_api' => (string) $product->product_api,
                'amount_mode' => ($product->amount_mode ?? ProductAmountMode::Fixed)->value,
                'reference_quantity' => ($product->amount_mode ?? ProductAmountMode::Fixed) === ProductAmountMode::Custom
                    ? $referenceQuantity
                    : null,
                'status' => SupplierPriceScanItemStatus::Pending,
            ]);
        }

        return $scan->load('items');
    }

    /**
     * @return array<string, mixed>
     */
    public function buildWorkerPayload(SupplierPriceScan $scan): array
    {
        $scan->loadMissing('items');
        $supplier = $this->automationService->supplierConfig($scan->supplier_key) ?? [];
        $referenceQuantity = (int) data_get($scan->meta, 'custom_reference_quantity', 1000);
        $timeoutSeconds = (int) config('fulfillment_automation.price_scan.run_timeout_seconds', 3600);

        return [
            'scan_uuid' => $scan->uuid,
            'supplier_key' => $scan->supplier_key,
            'driver' => $supplier['driver'] ?? $scan->supplier_key,
            'session_key' => $supplier['session_key'] ?? $scan->supplier_key.'-main',
            'custom_reference_quantity' => $referenceQuantity,
            'delay_ms_between_products' => (int) config('fulfillment_automation.price_scan.delay_ms_between_products', 400),
            'items' => $scan->items->map(static fn (SupplierPriceScanItem $item): array => [
                'product_id' => $item->product_id,
                'product_api' => $item->product_api,
                'amount_mode' => $item->amount_mode,
                'reference_quantity' => $item->reference_quantity,
            ])->values()->all(),
            'credentials' => $supplier['credentials'] ?? [],
            'callback_url' => URL::to('/internal/automation/price-scans/'.$scan->uuid.'/result'),
            'expires_at' => now()->addSeconds($timeoutSeconds)->toIso8601String(),
        ];
    }

    public function driftTolerance(): float
    {
        return (float) config('fulfillment_automation.price_scan.drift_tolerance', 0.0001);
    }

    public function hasPriceDrift(Product $product): bool
    {
        $scanned = $product->supplier_scanned_price;
        $entry = $product->getRawOriginal('entry_price');

        if ($scanned === null || $entry === null || $entry === '') {
            return false;
        }

        return abs((float) $scanned - (float) $entry) > $this->driftTolerance();
    }

    public function wasimCredentialsConfigured(): bool
    {
        $credentials = WebsiteSetting::instance()->wasimAutomationCredentialsFromDatabase();

        return is_string($credentials['username'] ?? null)
            && $credentials['username'] !== ''
            && is_string($credentials['password'] ?? null)
            && $credentials['password'] !== '';
    }

    public function latestActiveScan(string $supplierKey = 'wasim'): ?SupplierPriceScan
    {
        return SupplierPriceScan::query()
            ->where('supplier_key', $supplierKey)
            ->whereIn('status', [
                SupplierPriceScanStatus::Pending,
                SupplierPriceScanStatus::Running,
            ])
            ->latest('id')
            ->first();
    }

    public function latestCompletedScan(string $supplierKey = 'wasim'): ?SupplierPriceScan
    {
        return SupplierPriceScan::query()
            ->where('supplier_key', $supplierKey)
            ->where('status', SupplierPriceScanStatus::Completed)
            ->latest('finished_at')
            ->first();
    }

    /**
     * @return Builder<Product>
     */
    public function monitorProductsQuery(?int $packageId, string $filter = 'drifted'): Builder
    {
        $query = $this->scannableProductsQuery($packageId);

        return match ($filter) {
            'errors' => $query->whereNotNull('supplier_scan_error'),
            'never_scanned' => $query->whereNull('supplier_scanned_at'),
            'flagged' => $query->whereNotNull('supplier_price_flag_reason'),
            'all' => $query,
            'unchanged' => $this->queryProductsByIds(
                $this->scannableProducts($packageId)
                    ->reject(fn (Product $product): bool => $this->hasPriceDrift($product))
                    ->reject(fn (Product $product): bool => $product->supplier_scanned_price === null || $product->supplier_scan_error !== null)
                    ->pluck('id')
                    ->all(),
            ),
            default => $this->queryProductsByIds(
                $this->scannableProducts($packageId)
                    ->filter(fn (Product $product): bool => $this->hasPriceDrift($product) || $this->hasReactiveFlag($product))
                    ->pluck('id')
                    ->all(),
            ),
        };
    }

    public function hasReactiveFlag(Product $product): bool
    {
        return is_string($product->supplier_price_flag_reason)
            && $product->supplier_price_flag_reason !== '';
    }

    public function flagReasonLabel(Product $product): ?string
    {
        if (! $this->hasReactiveFlag($product)) {
            return null;
        }

        return match ($product->supplier_price_flag_reason) {
            SupplierPriceFlagReason::MarginInsufficient->value => __('messages.price_drift_flag_margin_insufficient'),
            SupplierPriceFlagReason::FulfillmentMismatch->value => __('messages.price_drift_flag_fulfillment_mismatch'),
            default => $product->supplier_price_flag_reason,
        };
    }

    public function normalizeObservedSupplierPrice(Product $product, float $observedTotal, ?int $quantity = null): ?float
    {
        if ($observedTotal <= 0) {
            return null;
        }

        $mode = $product->amount_mode ?? ProductAmountMode::Fixed;

        if ($mode === ProductAmountMode::Custom) {
            $qty = $quantity ?? (int) config('fulfillment_automation.price_scan.custom_reference_quantity', 1000);

            if ($qty <= 0) {
                return null;
            }

            return $observedTotal / $qty;
        }

        return $observedTotal;
    }

    public function flagProductFromFulfillmentObservation(
        Fulfillment $fulfillment,
        float $observedTotal,
        ?int $quantity,
        SupplierPriceFlagReason $reason,
    ): bool {
        $fulfillment->loadMissing('orderItem.product.package');

        $product = $fulfillment->orderItem?->product;

        if ($product === null) {
            return false;
        }

        if ($product->package?->fulfillment_provider !== 'browser:wasim') {
            return false;
        }

        if (! is_string($product->product_api) || trim($product->product_api) === '') {
            return false;
        }

        $normalized = $this->normalizeObservedSupplierPrice($product, $observedTotal, $quantity);

        if ($normalized === null) {
            return false;
        }

        if ($reason === SupplierPriceFlagReason::FulfillmentMismatch && ! $this->hasPriceDriftAgainst($product, $normalized)) {
            return false;
        }

        $product->update([
            'supplier_scanned_price' => $normalized,
            'supplier_scanned_at' => now(),
            'supplier_scan_error' => null,
            'supplier_price_flag_reason' => $reason->value,
            'supplier_price_flagged_at' => now(),
        ]);

        return true;
    }

    public function clearReactiveFlag(Product $product): void
    {
        if (! $this->hasReactiveFlag($product)) {
            return;
        }

        $product->update([
            'supplier_price_flag_reason' => null,
            'supplier_price_flagged_at' => null,
        ]);
    }

    public function hasPriceDriftAgainst(Product $product, float $observedEntryPrice): bool
    {
        $entry = $product->getRawOriginal('entry_price');

        if ($entry === null || $entry === '') {
            return true;
        }

        return abs($observedEntryPrice - (float) $entry) > $this->driftTolerance();
    }

    /**
     * @return array{
     *     drifted: int,
     *     flagged: int,
     *     unchanged: int,
     *     errors: int,
     *     never_scanned: int,
     *     scannable_total: int,
     *     last_scan_at: ?\Illuminate\Support\Carbon,
     *     scan_running: bool
     * }
     */
    public function monitorStats(?int $packageId = null): array
    {
        $products = $this->scannableProducts($packageId);
        $scannableTotal = $products->count();

        $neverScanned = $products->whereNull('supplier_scanned_at')->count();
        $errors = $products->whereNotNull('supplier_scan_error')->count();
        $drifted = $products->filter(
            fn (Product $product): bool => $this->hasPriceDrift($product) || $this->hasReactiveFlag($product),
        )->count();
        $flagged = $products->filter(fn (Product $product): bool => $this->hasReactiveFlag($product))->count();
        $unchanged = $products
            ->reject(fn (Product $product): bool => $this->hasPriceDrift($product))
            ->reject(fn (Product $product): bool => $product->supplier_scanned_price === null || $product->supplier_scan_error !== null)
            ->count();

        $lastScan = $this->latestCompletedScan();

        return [
            'drifted' => $drifted,
            'flagged' => $flagged,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'never_scanned' => $neverScanned,
            'scannable_total' => $scannableTotal,
            'last_scan_at' => $lastScan?->finished_at,
            'scan_running' => $this->hasRunningScan(),
        ];
    }

    public function driftPercent(Product $product): ?float
    {
        $scanned = $product->supplier_scanned_price;
        $entry = $product->getRawOriginal('entry_price');

        if ($scanned === null || $entry === null || $entry === '' || (float) $entry === 0.0) {
            return null;
        }

        return (((float) $scanned - (float) $entry) / (float) $entry) * 100;
    }

    public function buildWasimProductUrl(Product $product): ?string
    {
        $api = $product->product_api;

        if (! is_string($api) || trim($api) === '') {
            return null;
        }

        return $this->automationService->buildWasimProductUrl(trim($api));
    }

    public function formatScannedPrice(Product $product): ?string
    {
        $raw = $product->getRawOriginal('supplier_scanned_price');

        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->normalizeDecimalString((string) $raw);
    }

    public function formatEntryPrice(Product $product): ?string
    {
        $raw = $product->getRawOriginal('entry_price');

        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->normalizeDecimalString((string) $raw);
    }

    private function normalizeDecimalString(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        if (! str_contains($value, '.')) {
            return $value;
        }

        [$int, $frac] = explode('.', $value, 2);
        $frac = rtrim($frac, '0');

        return $frac === '' ? $int : "{$int}.{$frac}";
    }

    /**
     * @param  list<int>  $ids
     * @return Builder<Product>
     */
    private function queryProductsByIds(array $ids): Builder
    {
        if ($ids === []) {
            return Product::query()->whereRaw('0 = 1');
        }

        return Product::query()
            ->whereIn('id', $ids)
            ->with('package:id,name,fulfillment_provider')
            ->orderBy('package_id')
            ->orderBy('order')
            ->orderBy('id');
    }
}
