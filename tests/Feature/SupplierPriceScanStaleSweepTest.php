<?php

declare(strict_types=1);

use App\Enums\ProductAmountMode;
use App\Enums\SupplierPriceScanItemStatus;
use App\Enums\SupplierPriceScanStatus;
use App\Models\SupplierPriceScan;
use App\Models\SupplierPriceScanItem;
use App\Models\WebsiteSetting;
use App\Services\SupplierPriceScanService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
        'fulfillment_automation.price_scan.enabled' => true,
        'fulfillment_automation.price_scan.run_timeout_seconds' => 3600,
    ]);

    WebsiteSetting::instance()->update([
        'automation_enabled' => true,
        'wasim_automation_username' => 'wasim@test.com',
        'wasim_automation_password' => 'secret-pass',
    ]);
});

test('stale running scan is failed and unlocks new scans', function () {
    Http::fake([
        'automation-worker.test/v1/price-scans' => Http::response([
            'accepted' => true,
        ], 202),
    ]);

    $product = makeWasimScannableProduct();

    $stale = SupplierPriceScan::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_key' => 'wasim',
        'status' => SupplierPriceScanStatus::Running,
        'products_total' => 1,
        'started_at' => now()->subHours(2),
    ]);

    SupplierPriceScanItem::query()->create([
        'supplier_price_scan_id' => $stale->id,
        'product_id' => $product->id,
        'product_api' => (string) $product->product_api,
        'amount_mode' => ProductAmountMode::Fixed->value,
        'status' => SupplierPriceScanItemStatus::Pending,
    ]);

    expect(app(SupplierPriceScanService::class)->hasRunningScan())->toBeTrue();

    $this->artisan('wasim:sweep-stale-price-scans')
        ->assertSuccessful();

    $stale->refresh();

    expect($stale->status)->toBe(SupplierPriceScanStatus::Failed)
        ->and($stale->finished_at)->not->toBeNull()
        ->and(data_get($stale->meta, 'batch_error_code'))->toBe('scan_timeout')
        ->and($stale->items()->first()?->status)->toBe(SupplierPriceScanItemStatus::Failed)
        ->and($stale->items()->first()?->error_code)->toBe('scan_timeout')
        ->and(app(SupplierPriceScanService::class)->hasRunningScan())->toBeFalse();

    $this->artisan('wasim:scan-prices')
        ->assertSuccessful();
});

test('fresh running scan is left alone by stale sweep', function () {
    $scan = SupplierPriceScan::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_key' => 'wasim',
        'status' => SupplierPriceScanStatus::Running,
        'products_total' => 1,
        'started_at' => now()->subMinutes(10),
    ]);

    $this->artisan('wasim:sweep-stale-price-scans')
        ->assertSuccessful()
        ->expectsOutput('No stale supplier price scans found.');

    expect($scan->fresh()->status)->toBe(SupplierPriceScanStatus::Running);
});

test('stale sweep respects custom seconds option', function () {
    $scan = SupplierPriceScan::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_key' => 'wasim',
        'status' => SupplierPriceScanStatus::Running,
        'products_total' => 1,
        'started_at' => now()->subMinutes(20),
    ]);

    $this->artisan('wasim:sweep-stale-price-scans', ['--seconds' => 600])
        ->assertSuccessful();

    expect($scan->fresh()->status)->toBe(SupplierPriceScanStatus::Failed);
});

test('stale price scan sweep is registered on the schedule', function () {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.price_scan.enabled' => true,
    ]);

    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($scheduledEvent): bool => str_contains((string) ($scheduledEvent->command ?? ''), 'wasim:sweep-stale-price-scans'));

    expect($event)->not->toBeNull();
});
