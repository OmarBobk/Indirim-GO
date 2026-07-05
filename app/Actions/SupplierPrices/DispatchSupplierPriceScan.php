<?php

declare(strict_types=1);

namespace App\Actions\SupplierPrices;

use App\Enums\SupplierPriceScanStatus;
use App\Models\SupplierPriceScan;
use App\Services\FulfillmentAutomationService;
use App\Services\SupplierPriceScanService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class DispatchSupplierPriceScan
{
    public function __construct(
        private readonly SupplierPriceScanService $scanService,
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    public function handle(SupplierPriceScan $scan): SupplierPriceScan
    {
        return DB::transaction(function () use ($scan): SupplierPriceScan {
            $lockedScan = SupplierPriceScan::query()
                ->whereKey($scan->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedScan->status !== SupplierPriceScanStatus::Pending) {
                return $lockedScan;
            }

            $payload = $this->scanService->buildWorkerPayload($lockedScan);
            $rawBody = json_encode($payload, JSON_THROW_ON_ERROR);
            $timestamp = time();
            $signature = $this->automationService->signPayload($rawBody, $timestamp);

            $workerUrl = rtrim((string) config('fulfillment_automation.worker_url'), '/');
            $timeout = (int) config('fulfillment_automation.timeouts.dispatch_seconds', 15);

            try {
                $response = Http::timeout($timeout)
                    ->withHeaders([
                        'Content-Type' => 'application/json',
                        'X-Automation-Timestamp' => (string) $timestamp,
                        'X-Automation-Signature' => $signature,
                    ])
                    ->withBody($rawBody, 'application/json')
                    ->post($workerUrl.'/v1/price-scans');

                if (! $response->successful()) {
                    throw new RuntimeException('Worker rejected price scan: HTTP '.$response->status());
                }
            } catch (Throwable $exception) {
                Log::error('Supplier price scan dispatch failed', [
                    'scan_uuid' => $lockedScan->uuid,
                    'error' => $exception->getMessage(),
                ]);

                $lockedScan->fill([
                    'status' => SupplierPriceScanStatus::Failed,
                    'finished_at' => now(),
                    'meta' => array_merge($lockedScan->meta ?? [], [
                        'dispatch_error' => $exception->getMessage(),
                    ]),
                ])->save();

                throw $exception;
            }

            $lockedScan->fill([
                'status' => SupplierPriceScanStatus::Running,
                'started_at' => now(),
            ])->save();

            return $lockedScan->refresh();
        });
    }
}
