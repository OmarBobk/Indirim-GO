<?php

declare(strict_types=1);

use App\Actions\SupplierPrices\IngestSupplierPriceScanResult;
use App\Enums\ProductAmountMode;
use App\Enums\SupplierPriceScanItemStatus;
use App\Enums\SupplierPriceScanStatus;
use App\Models\Package;
use App\Models\Product;
use App\Models\SupplierPriceScan;
use App\Models\SupplierPriceScanItem;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Notifications\WasimPriceDriftReviewNotification;
use App\Services\SupplierPriceScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
    ]);

    WebsiteSetting::instance()->update([
        'automation_enabled' => true,
        'wasim_automation_username' => 'wasim@test.com',
        'wasim_automation_password' => 'secret-pass',
    ]);
});

function signAutomationRequest(string $rawBody, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $secret = (string) config('fulfillment_automation.callback_secret');
    $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

    return [
        'X-Automation-Timestamp' => (string) $timestamp,
        'X-Automation-Signature' => $signature,
    ];
}

function makeWasimScannableProduct(array $overrides = []): Product
{
    $package = Package::factory()->create([
        'fulfillment_provider' => 'browser:wasim',
    ]);

    return Product::factory()->create(array_merge([
        'package_id' => $package->id,
        'product_api' => 'Customer/Home/ProductRequest?productId='.fake()->unique()->numberBetween(100, 999),
        'entry_price' => 1.25,
    ], $overrides));
}

test('supplier price scan service finds only wasim products with product api', function () {
    $included = makeWasimScannableProduct();
    $manualPackage = Package::factory()->create(['fulfillment_provider' => null]);
    Product::factory()->create([
        'package_id' => $manualPackage->id,
        'product_api' => 'Customer/Home/ProductRequest?productId=1',
    ]);
    $wasimPackage = Package::factory()->create(['fulfillment_provider' => 'browser:wasim']);
    Product::factory()->create([
        'package_id' => $wasimPackage->id,
        'product_api' => null,
    ]);

    $products = app(SupplierPriceScanService::class)->scannableProducts();

    expect($products)->toHaveCount(1)
        ->and($products->first()?->id)->toBe($included->id);
});

test('ingest supplier price scan result updates product snapshots and scan counters', function () {
    $product = makeWasimScannableProduct(['entry_price' => 2.5]);
    $scan = SupplierPriceScan::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_key' => 'wasim',
        'status' => SupplierPriceScanStatus::Running,
        'products_total' => 1,
        'started_at' => now(),
    ]);
    SupplierPriceScanItem::query()->create([
        'supplier_price_scan_id' => $scan->id,
        'product_id' => $product->id,
        'product_api' => (string) $product->product_api,
        'amount_mode' => ProductAmountMode::Fixed->value,
        'status' => SupplierPriceScanItemStatus::Pending,
    ]);

    app(IngestSupplierPriceScanResult::class)->handle($scan, [
        'status' => 'completed',
        'items' => [
            [
                'product_id' => $product->id,
                'ok' => true,
                'scanned_price' => 2.75,
                'displayed_raw' => '2.75',
            ],
        ],
    ]);

    $scan->refresh();
    $product->refresh();

    expect($scan->status)->toBe(SupplierPriceScanStatus::Completed)
        ->and($scan->products_ok)->toBe(1)
        ->and($scan->products_failed)->toBe(0)
        ->and((float) $product->supplier_scanned_price)->toBe(2.75)
        ->and($product->supplier_scanned_at)->not->toBeNull()
        ->and($product->supplier_scan_error)->toBeNull();

    expect(app(SupplierPriceScanService::class)->hasPriceDrift($product))->toBeTrue();
});

test('price scan callback route accepts signed ingest payload', function () {
    $product = makeWasimScannableProduct();
    $scan = SupplierPriceScan::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_key' => 'wasim',
        'status' => SupplierPriceScanStatus::Running,
        'products_total' => 1,
        'started_at' => now(),
    ]);
    SupplierPriceScanItem::query()->create([
        'supplier_price_scan_id' => $scan->id,
        'product_id' => $product->id,
        'product_api' => (string) $product->product_api,
        'amount_mode' => ProductAmountMode::Fixed->value,
        'status' => SupplierPriceScanItemStatus::Pending,
    ]);

    $body = json_encode([
        'status' => 'completed',
        'items' => [
            [
                'product_id' => $product->id,
                'ok' => true,
                'scanned_price' => 1.11,
                'displayed_raw' => '1.11',
            ],
        ],
    ], JSON_THROW_ON_ERROR);

    $this->postJson(
        route('automation.price-scans.result', ['uuid' => $scan->uuid]),
        json_decode($body, true, 512, JSON_THROW_ON_ERROR),
        signAutomationRequest($body),
    )->assertSuccessful();

    expect((float) $product->refresh()->supplier_scanned_price)->toBe(1.11);
});

test('wasim scan prices command dispatches batched worker request', function () {
    Http::fake([
        'automation-worker.test/v1/price-scans' => Http::response([
            'accepted' => true,
            'scan_uuid' => 'ignored',
        ], 202),
    ]);

    makeWasimScannableProduct();
    makeWasimScannableProduct();

    $this->artisan('wasim:scan-prices')
        ->assertExitCode(0);

    Http::assertSent(function ($request): bool {
        if ($request->url() !== 'http://automation-worker.test/v1/price-scans') {
            return false;
        }

        $items = $request->data()['items'] ?? [];

        return is_array($items) && count($items) === 2;
    });

    expect(SupplierPriceScan::query()->count())->toBe(1)
        ->and(SupplierPriceScan::query()->first()?->status)->toBe(SupplierPriceScanStatus::Running);
});

test('wasim scan prices command refuses duplicate running scan', function () {
    SupplierPriceScan::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_key' => 'wasim',
        'status' => SupplierPriceScanStatus::Running,
        'products_total' => 0,
        'started_at' => now(),
    ]);

    makeWasimScannableProduct();

    $this->artisan('wasim:scan-prices')
        ->assertExitCode(1);
});

function priceReviewRecipient(): User
{
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::firstOrCreate(['name' => 'update_product_prices']);
    $role = Role::firstOrCreate(['name' => 'price_editor']);
    $role->syncPermissions(['update_product_prices']);
    $user = User::factory()->create();
    $user->assignRole('price_editor');

    return $user;
}

test('completed scan with drift notifies price review recipients after commit', function () {
    Notification::fake();

    $recipient = priceReviewRecipient();
    $product = makeWasimScannableProduct(['entry_price' => 2.5]);
    $scan = SupplierPriceScan::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_key' => 'wasim',
        'status' => SupplierPriceScanStatus::Running,
        'products_total' => 1,
        'started_at' => now(),
    ]);
    SupplierPriceScanItem::query()->create([
        'supplier_price_scan_id' => $scan->id,
        'product_id' => $product->id,
        'product_api' => (string) $product->product_api,
        'amount_mode' => ProductAmountMode::Fixed->value,
        'status' => SupplierPriceScanItemStatus::Pending,
    ]);

    app(IngestSupplierPriceScanResult::class)->handle($scan, [
        'status' => 'completed',
        'items' => [
            [
                'product_id' => $product->id,
                'ok' => true,
                'scanned_price' => 2.75,
                'displayed_raw' => '2.75',
            ],
        ],
    ]);

    Notification::assertSentTo($recipient, WasimPriceDriftReviewNotification::class);
});

test('completed scan without drift does not notify price review recipients', function () {
    Notification::fake();

    $recipient = priceReviewRecipient();
    $product = makeWasimScannableProduct(['entry_price' => 2.5]);
    $scan = SupplierPriceScan::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_key' => 'wasim',
        'status' => SupplierPriceScanStatus::Running,
        'products_total' => 1,
        'started_at' => now(),
    ]);
    SupplierPriceScanItem::query()->create([
        'supplier_price_scan_id' => $scan->id,
        'product_id' => $product->id,
        'product_api' => (string) $product->product_api,
        'amount_mode' => ProductAmountMode::Fixed->value,
        'status' => SupplierPriceScanItemStatus::Pending,
    ]);

    app(IngestSupplierPriceScanResult::class)->handle($scan, [
        'status' => 'completed',
        'items' => [
            [
                'product_id' => $product->id,
                'ok' => true,
                'scanned_price' => 2.5,
                'displayed_raw' => '2.5',
            ],
        ],
    ]);

    Notification::assertNothingSentTo($recipient);
});

test('failed scan batch does not notify price review recipients', function () {
    Notification::fake();

    $recipient = priceReviewRecipient();
    $product = makeWasimScannableProduct(['entry_price' => 2.5]);
    $scan = SupplierPriceScan::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_key' => 'wasim',
        'status' => SupplierPriceScanStatus::Running,
        'products_total' => 1,
        'started_at' => now(),
    ]);
    SupplierPriceScanItem::query()->create([
        'supplier_price_scan_id' => $scan->id,
        'product_id' => $product->id,
        'product_api' => (string) $product->product_api,
        'amount_mode' => ProductAmountMode::Fixed->value,
        'status' => SupplierPriceScanItemStatus::Pending,
    ]);

    app(IngestSupplierPriceScanResult::class)->handle($scan, [
        'status' => 'failed',
        'error_code' => 'login_failed',
        'message' => 'Could not log in.',
        'items' => [],
    ]);

    Notification::assertNothingSentTo($recipient);
});

test('wasim scan prices command is registered on the schedule when enabled', function () {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.price_scan.enabled' => true,
        'fulfillment_automation.price_scan.schedule_enabled' => true,
    ]);

    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($scheduledEvent): bool => str_contains((string) ($scheduledEvent->command ?? ''), 'wasim:scan-prices'));

    expect($event)->not->toBeNull();
});
