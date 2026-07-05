<?php

declare(strict_types=1);

namespace App\Actions\SupplierPrices;

use App\Enums\SupplierPriceScanItemStatus;
use App\Enums\SupplierPriceScanStatus;
use App\Models\Product;
use App\Models\SupplierPriceScan;
use App\Models\SupplierPriceScanItem;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class IngestSupplierPriceScanResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function handle(SupplierPriceScan $scan, array $payload): SupplierPriceScan
    {
        return DB::transaction(function () use ($scan, $payload): SupplierPriceScan {
            $lockedScan = SupplierPriceScan::query()
                ->whereKey($scan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedScan->status->isTerminal()) {
                return $lockedScan;
            }

            $items = $payload['items'] ?? null;

            if (! is_array($items)) {
                throw new InvalidArgumentException('Price scan callback is missing items array.');
            }

            $okCount = 0;
            $failedCount = 0;

            foreach ($items as $itemPayload) {
                if (! is_array($itemPayload)) {
                    continue;
                }

                $productId = (int) ($itemPayload['product_id'] ?? 0);

                if ($productId <= 0) {
                    continue;
                }

                $scanItem = SupplierPriceScanItem::query()
                    ->where('supplier_price_scan_id', $lockedScan->id)
                    ->where('product_id', $productId)
                    ->lockForUpdate()
                    ->first();

                if ($scanItem === null) {
                    continue;
                }

                $isOk = (bool) ($itemPayload['ok'] ?? false);

                if ($isOk) {
                    $scannedPrice = $itemPayload['scanned_price'] ?? null;

                    if (! is_numeric($scannedPrice)) {
                        $failedCount++;
                        $this->markItemFailed($scanItem, 'invalid_scanned_price', 'Worker returned ok without a numeric scanned_price.');

                        continue;
                    }

                    $okCount++;
                    $this->markItemOk(
                        $scanItem,
                        (float) $scannedPrice,
                        is_string($itemPayload['displayed_raw'] ?? null) ? $itemPayload['displayed_raw'] : null,
                    );
                    $this->updateProductSnapshot($productId, (float) $scannedPrice);

                    continue;
                }

                $failedCount++;
                $this->markItemFailed(
                    $scanItem,
                    is_string($itemPayload['error_code'] ?? null) ? $itemPayload['error_code'] : 'scan_failed',
                    is_string($itemPayload['message'] ?? null) ? $itemPayload['message'] : 'Supplier price scan failed.',
                );
                $this->updateProductScanError(
                    $productId,
                    is_string($itemPayload['error_code'] ?? null) ? $itemPayload['error_code'] : 'scan_failed',
                );
            }

            $batchFailed = is_string($payload['error_code'] ?? null) && $items === [];

            $lockedScan->fill([
                'status' => $batchFailed ? SupplierPriceScanStatus::Failed : SupplierPriceScanStatus::Completed,
                'products_ok' => $okCount,
                'products_failed' => $failedCount,
                'finished_at' => now(),
                'meta' => array_merge($lockedScan->meta ?? [], [
                    'log_excerpt' => is_array($payload['log_excerpt'] ?? null) ? $payload['log_excerpt'] : null,
                    'batch_error_code' => $batchFailed ? $payload['error_code'] : null,
                    'batch_message' => $batchFailed ? ($payload['message'] ?? null) : null,
                ]),
            ])->save();

            return $lockedScan->refresh();
        });
    }

    private function markItemOk(SupplierPriceScanItem $item, float $scannedPrice, ?string $displayedRaw): void
    {
        $item->fill([
            'status' => SupplierPriceScanItemStatus::Ok,
            'scanned_price' => $scannedPrice,
            'displayed_raw' => $displayedRaw,
            'error_code' => null,
            'error_message' => null,
            'scanned_at' => now(),
        ])->save();
    }

    private function markItemFailed(SupplierPriceScanItem $item, string $errorCode, string $message): void
    {
        $item->fill([
            'status' => SupplierPriceScanItemStatus::Failed,
            'scanned_price' => null,
            'displayed_raw' => null,
            'error_code' => $errorCode,
            'error_message' => $message,
            'scanned_at' => now(),
        ])->save();
    }

    private function updateProductSnapshot(int $productId, float $scannedPrice): void
    {
        Product::query()
            ->whereKey($productId)
            ->update([
                'supplier_scanned_price' => $scannedPrice,
                'supplier_scanned_at' => now(),
                'supplier_scan_error' => null,
            ]);
    }

    private function updateProductScanError(int $productId, string $errorCode): void
    {
        Product::query()
            ->whereKey($productId)
            ->update([
                'supplier_scan_error' => $errorCode,
                'supplier_scanned_at' => now(),
            ]);
    }
}
