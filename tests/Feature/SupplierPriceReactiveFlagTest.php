<?php

declare(strict_types=1);

use App\Actions\Fulfillments\IngestFulfillmentAutomationResult;
use App\Actions\SupplierPrices\ApplyWasimScannedEntryPrices;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\SupplierPriceFlagReason;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Notifications\WasimPriceReactiveFlagNotification;
use App\Services\SupplierPriceScanService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['fulfillment_automation.enabled' => true]);
    Http::fake();
    Notification::fake();
    Role::firstOrCreate(['name' => 'admin']);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    Permission::firstOrCreate(['name' => 'update_product_prices']);
});

if (! function_exists('priceReviewRecipient')) {
    function priceReviewRecipient(): User
    {
        $role = Role::firstOrCreate(['name' => 'price_editor']);
        $role->syncPermissions(['update_product_prices']);
        $user = User::factory()->create();
        $user->assignRole('price_editor');

        return $user;
    }
}

function makeWasimFulfillmentOrder(float $entryPrice = 2.5): Fulfillment
{
    $user = User::factory()->create();
    $package = Package::factory()->create([
        'fulfillment_provider' => 'browser:wasim',
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'product_api' => 'Customer/Home/ProductRequest?productId=501',
        'entry_price' => $entryPrice,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 25,
        'fee' => 0,
        'total' => 25,
        'status' => OrderStatus::Paid,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 25,
        'quantity' => 1,
        'line_total' => 25,
        'status' => OrderItemStatus::Pending,
    ]);

    (new \App\Actions\Fulfillments\CreateFulfillmentsForOrder)->handle($order);

    return Fulfillment::query()->where('order_id', $order->id)->firstOrFail();
}

test('margin insufficient fulfillment flags product with observed supplier total', function () {
    $recipient = priceReviewRecipient();
    $fulfillment = makeWasimFulfillmentOrder(2.5);
    $product = Product::query()->findOrFail($fulfillment->orderItem->product_id);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:margin-flag',
        'started_at' => now(),
    ]);

    $fulfillment->update(['status' => FulfillmentStatus::Processing, 'provider' => 'browser:wasim']);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'failed',
        'error_code' => 'margin_insufficient',
        'message' => 'Order unit price must be greater than Wasim total.',
        'delivered_payload' => [
            'checkpoint' => 'price_check',
            'supplier_total' => 2.8,
            'supplier_total_raw' => '2.8',
        ],
    ]);

    $product->refresh();

    expect($product->supplier_price_flag_reason)->toBe(SupplierPriceFlagReason::MarginInsufficient->value)
        ->and($product->supplier_price_flagged_at)->not->toBeNull()
        ->and((float) $product->supplier_scanned_price)->toBe(2.8)
        ->and(app(SupplierPriceScanService::class)->hasPriceDrift($product))->toBeTrue();

    Notification::assertSentTo($recipient, WasimPriceReactiveFlagNotification::class);
});

test('submitted fulfillment with mismatched supplier entry price flags product', function () {
    $recipient = priceReviewRecipient();
    $fulfillment = makeWasimFulfillmentOrder(1.0);
    $product = Product::query()->findOrFail($fulfillment->orderItem->product_id);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:entry-flag',
        'started_at' => now(),
    ]);

    $fulfillment->update(['status' => FulfillmentStatus::Processing, 'provider' => 'browser:wasim']);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'submitted',
        'external_order_id' => '9001',
        'delivered_payload' => [
            'supplier_entry_price' => 1.12,
            'supplier_status' => 'pending',
        ],
    ]);

    $product->refresh();

    expect($product->supplier_price_flag_reason)->toBe(SupplierPriceFlagReason::FulfillmentMismatch->value)
        ->and((float) $product->supplier_scanned_price)->toBe(1.12);

    Notification::assertSentTo($recipient, WasimPriceReactiveFlagNotification::class);
});

test('matching supplier entry price on success does not flag product', function () {
    $recipient = priceReviewRecipient();
    $fulfillment = makeWasimFulfillmentOrder(1.07);
    $product = Product::query()->findOrFail($fulfillment->orderItem->product_id);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:no-flag',
        'started_at' => now(),
    ]);

    $fulfillment->update(['status' => FulfillmentStatus::Processing, 'provider' => 'browser:wasim']);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'success',
        'external_order_id' => '9002',
        'delivered_payload' => [
            'supplier_entry_price' => 1.07,
            'supplier_status' => 'accept',
        ],
    ]);

    $product->refresh();

    expect($product->supplier_price_flag_reason)->toBeNull();

    Notification::assertNothingSentTo($recipient);
});

test('reactive flag notification is not sent twice for the same fulfillment', function () {
    $recipient = priceReviewRecipient();
    $fulfillment = makeWasimFulfillmentOrder(2.5);
    $product = Product::query()->findOrFail($fulfillment->orderItem->product_id);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) \Illuminate\Support\Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:dedup',
        'started_at' => now(),
    ]);

    $fulfillment->update(['status' => FulfillmentStatus::Processing, 'provider' => 'browser:wasim']);

    $payload = [
        'outcome' => 'failed',
        'error_code' => 'margin_insufficient',
        'message' => 'Order unit price must be greater than Wasim total.',
        'delivered_payload' => [
            'checkpoint' => 'price_check',
            'supplier_total' => 2.8,
        ],
    ];

    app(IngestFulfillmentAutomationResult::class)->handle($run, $payload);

    app(\App\Actions\SupplierPrices\FlagProductSupplierPriceFromFulfillment::class)
        ->handleMarginInsufficient($fulfillment->refresh(), $payload['delivered_payload']);

    Notification::assertSentToTimes($recipient, WasimPriceReactiveFlagNotification::class, 1);
});

test('applying wasim scanned price clears reactive flag', function () {
    $package = Package::factory()->create(['fulfillment_provider' => 'browser:wasim']);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'product_api' => 'Customer/Home/ProductRequest?productId=777',
        'entry_price' => 2.0,
        'supplier_scanned_price' => 2.5,
        'supplier_scanned_at' => now(),
        'supplier_price_flag_reason' => SupplierPriceFlagReason::MarginInsufficient->value,
        'supplier_price_flagged_at' => now(),
    ]);

    app(ApplyWasimScannedEntryPrices::class)->handleOne($product->id);

    $product->refresh();

    expect($product->supplier_price_flag_reason)->toBeNull()
        ->and((float) $product->entry_price)->toBe(2.5);
});
