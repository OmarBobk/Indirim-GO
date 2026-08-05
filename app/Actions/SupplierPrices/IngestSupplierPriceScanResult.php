<?php

declare(strict_types=1);

namespace App\Actions\SupplierPrices;

use App\Actions\Fulfillments\ObserveAutomationSafetySignal;
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
            /** @var list<array{product_id: int, error_code: string}> $circuitSignals */
            $circuitSignals = [];

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

                $errorCode = is_string($itemPayload['error_code'] ?? null) ? $itemPayload['error_code'] : 'scan_failed';
                $failedCount++;
                $this->markItemFailed(
                    $scanItem,
                    $errorCode,
                    is_string($itemPayload['message'] ?? null) ? $itemPayload['message'] : 'Supplier price scan failed.',
                );
                $this->updateProductScanError($productId, $errorCode);
                $circuitSignals[] = ['product_id' => $productId, 'error_code' => $errorCode];
            }

            $batchFailed = is_string($payload['error_code'] ?? null) && $items === [];

            if ($batchFailed) {
                $circuitSignals[] = [
                    'product_id' => 0,
                    'error_code' => (string) $payload['error_code'],
                ];
            }

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

            $scanId = $lockedScan->id;
            $scanUuid = (string) $lockedScan->uuid;
            $completed = ! $batchFailed;

            DB::afterCommit(function () use ($scanId, $scanUuid, $completed, $circuitSignals): void {
                $this->observeCircuitSignals($scanUuid, $circuitSignals);

                if (! $completed) {
                    return;
                }

                app(NotifyWasimPriceDriftReview::class)->handle($scanId);
            });

            return $lockedScan->refresh();
        });
    }

    /**
     * @param  list<array{product_id: int, error_code: string}>  $signals
     */
    private function observeCircuitSignals(string $scanUuid, array $signals): void
    {
        $observer = app(ObserveAutomationSafetySignal::class);

        foreach ($signals as $signal) {
            $mapped = $this->mapPriceScanFailureCode($signal['error_code']);

            if ($mapped === null) {
                continue;
            }

            $observer->handle([
                'supplier_key' => 'wasim',
                'failure_code' => $mapped,
                'source_type' => 'price_scan',
                'source_key' => $scanUuid.':'.$signal['product_id'].':'.$mapped,
                'capability_hint' => 'price_scan',
                'occurred_at' => now(),
            ]);
        }
    }

    private function mapPriceScanFailureCode(string $errorCode): ?string
    {
        return match ($errorCode) {
            'unsupported_ui', 'ambiguous_ui', 'price_scan_ui_unsupported' => 'price_scan_ui_unsupported',
            'supplier_total_field_missing',
            'supplier_total_unparseable',
            'supplier_price_parse_failed',
            'quantity_field_missing',
            'price_scan_parse_failed' => 'price_scan_parse_failed',
            'authentication_required',
            'authentication_failed',
            'authenticated_contract_failed',
            'access_denied',
            'login_failed',
            'credentials_missing',
            'maintenance' => $errorCode,
            default => null,
        };
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
                'supplier_price_flag_reason' => null,
                'supplier_price_flagged_at' => null,
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
