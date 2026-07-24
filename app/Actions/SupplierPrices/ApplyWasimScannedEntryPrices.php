<?php

declare(strict_types=1);

namespace App\Actions\SupplierPrices;

use App\Actions\Products\UpdateProductEntryPrice;
use App\Models\Product;
use App\Services\SupplierPriceScanService;
use InvalidArgumentException;

class ApplyWasimScannedEntryPrices
{
    /**
     * @param  list<int>  $productIds
     */
    public function handle(array $productIds): int
    {
        if ($productIds === []) {
            return 0;
        }

        $updated = 0;

        $products = Product::query()
            ->whereIn('id', $productIds)
            ->whereNotNull('supplier_scanned_price')
            ->whereNull('supplier_scan_error')
            ->get();

        foreach ($products as $product) {
            $scanned = $product->getRawOriginal('supplier_scanned_price');

            if ($scanned === null || $scanned === '') {
                continue;
            }

            $entry = $product->getRawOriginal('entry_price');

            if ($entry !== null && $entry !== '' && (string) $entry === (string) $scanned) {
                continue;
            }

            app(UpdateProductEntryPrice::class)->handle($product, (string) $scanned);
            app(SupplierPriceScanService::class)->clearReactiveFlag($product->refresh());
            $updated++;
        }

        return $updated;
    }

    public function handleOne(int $productId): Product
    {
        $product = Product::query()->findOrFail($productId);

        if ($product->supplier_scanned_price === null || $product->supplier_scan_error !== null) {
            throw new InvalidArgumentException('Product does not have a successful Wasim price scan to apply.');
        }

        $updated = app(UpdateProductEntryPrice::class)->handle(
            $product,
            (string) $product->getRawOriginal('supplier_scanned_price'),
        );
        app(SupplierPriceScanService::class)->clearReactiveFlag($updated);

        return $updated;
    }
}
