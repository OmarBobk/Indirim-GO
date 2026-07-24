<?php

declare(strict_types=1);

namespace App\Actions\SupplierPrices;

use App\Enums\SupplierPriceScanItemStatus;
use App\Enums\SupplierPriceScanStatus;
use App\Models\SupplierPriceScan;
use App\Models\SupplierPriceScanItem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class FailStaleSupplierPriceScans
{
    /**
     * Fail pending/running scans that exceeded the timeout.
     *
     * @return Collection<int, SupplierPriceScan>
     */
    public function handle(?int $timeoutSeconds = null): Collection
    {
        $timeoutSeconds ??= (int) config('fulfillment_automation.price_scan.run_timeout_seconds', 3600);
        $cutoff = Carbon::now()->subSeconds(max(1, $timeoutSeconds));

        $staleScans = SupplierPriceScan::query()
            ->whereIn('status', [
                SupplierPriceScanStatus::Pending->value,
                SupplierPriceScanStatus::Running->value,
            ])
            ->where(function ($query) use ($cutoff): void {
                $query->where(function ($running) use ($cutoff): void {
                    $running->where('status', SupplierPriceScanStatus::Running->value)
                        ->where('started_at', '<=', $cutoff);
                })->orWhere(function ($pending) use ($cutoff): void {
                    $pending->where('status', SupplierPriceScanStatus::Pending->value)
                        ->where('created_at', '<=', $cutoff);
                });
            })
            ->orderBy('id')
            ->get();

        $failed = collect();

        foreach ($staleScans as $scan) {
            $failed->push($this->failScan($scan, $timeoutSeconds));
        }

        return $failed;
    }

    private function failScan(SupplierPriceScan $scan, int $timeoutSeconds): SupplierPriceScan
    {
        return DB::transaction(function () use ($scan, $timeoutSeconds): SupplierPriceScan {
            $lockedScan = SupplierPriceScan::query()
                ->whereKey($scan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedScan->status->isTerminal()) {
                return $lockedScan;
            }

            $pendingItems = SupplierPriceScanItem::query()
                ->where('supplier_price_scan_id', $lockedScan->id)
                ->where('status', SupplierPriceScanItemStatus::Pending->value)
                ->lockForUpdate()
                ->get();

            foreach ($pendingItems as $item) {
                $item->fill([
                    'status' => SupplierPriceScanItemStatus::Failed,
                    'error_code' => 'scan_timeout',
                    'error_message' => 'Scan exceeded timeout before the worker reported a result.',
                    'scanned_at' => now(),
                ])->save();
            }

            $failedCount = SupplierPriceScanItem::query()
                ->where('supplier_price_scan_id', $lockedScan->id)
                ->where('status', SupplierPriceScanItemStatus::Failed->value)
                ->count();

            $okCount = SupplierPriceScanItem::query()
                ->where('supplier_price_scan_id', $lockedScan->id)
                ->where('status', SupplierPriceScanItemStatus::Ok->value)
                ->count();

            $message = sprintf(
                'Scan timed out after %d seconds without a terminal worker callback.',
                $timeoutSeconds,
            );

            $lockedScan->fill([
                'status' => SupplierPriceScanStatus::Failed,
                'products_ok' => $okCount,
                'products_failed' => $failedCount,
                'finished_at' => now(),
                'meta' => array_merge($lockedScan->meta ?? [], [
                    'batch_error_code' => 'scan_timeout',
                    'batch_message' => $message,
                    'stale_swept_at' => now()->toIso8601String(),
                ]),
            ])->save();

            Log::warning('Supplier price scan marked stale/failed', [
                'scan_uuid' => $lockedScan->uuid,
                'supplier_key' => $lockedScan->supplier_key,
                'started_at' => optional($lockedScan->started_at)?->toIso8601String(),
                'timeout_seconds' => $timeoutSeconds,
                'products_total' => $lockedScan->products_total,
                'products_ok' => $okCount,
                'products_failed' => $failedCount,
            ]);

            return $lockedScan->refresh();
        });
    }
}
