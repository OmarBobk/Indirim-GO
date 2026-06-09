<?php

use App\Actions\Fulfillments\FailFulfillment;
use App\Actions\Orders\CheckoutFromPayload;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductAmountMode;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

test('checkout creates paid order and fulfillments from cart payload', function () {
    $user = User::factory()->create();
    $wallet = Wallet::create([
        'user_id' => $user->id,
        'type' => WalletType::Customer,
        'balance' => 300,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'name' => 'Google Play 100$',
        'entry_price' => 120,
    ]);

    $payload = [
        [
            'id' => $product->id,
            'quantity' => 2,
            'price' => 1,
            'name' => 'Tampered',
        ],
    ];

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('checkout', $payload);

    $order = Order::query()->first();

    expect($order)->not->toBeNull();
    expect($order->status)->toBe(OrderStatus::Paid);
    expect((float) $order->subtotal)->toBe(240.0);
    expect((float) $order->total)->toBe(240.0);

    $orderItem = OrderItem::query()->first();
    expect($orderItem)->not->toBeNull();
    expect($orderItem?->name)->toBe('Google Play 100$');

    $transaction = WalletTransaction::query()
        ->where('reference_type', Order::class)
        ->where('reference_id', $order->id)
        ->first();

    expect($transaction)->not->toBeNull();
    expect($transaction?->type)->toBe(WalletTransactionType::Purchase);
    expect($transaction?->direction)->toBe(WalletTransactionDirection::Debit);
    expect($transaction?->status)->toBe(WalletTransaction::STATUS_POSTED);

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(60.0);

    $fulfillments = Fulfillment::query()
        ->where('order_item_id', $orderItem->id)
        ->get();

    expect($fulfillments)->toHaveCount(2);
    expect($fulfillments->pluck('status')->unique()->all())->toBe([FulfillmentStatus::Queued]);
});

test('checkout fails when wallet balance is insufficient', function () {
    $user = User::factory()->create();

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    $payload = [
        [
            'id' => $product->id,
            'quantity' => 1,
        ],
    ];

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('checkout', $payload)
        ->assertSet('checkoutError', 'Insufficient wallet balance.');

    expect(Wallet::where('type', WalletType::Customer)->count())->toBe(1);
    expect(Order::count())->toBe(0);
    expect(WalletTransaction::count())->toBe(0);
});

test('checkout creates single fulfillment for custom amount products', function () {
    $user = User::factory()->create();
    Wallet::create([
        'user_id' => $user->id,
        'type' => WalletType::Customer,
        'balance' => 10000,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'name' => 'Custom Crystals',
        'entry_price' => 0.01,
        'amount_mode' => ProductAmountMode::Custom,
        'amount_unit_label' => 'crystals',
        'custom_amount_min' => 100,
        'custom_amount_max' => 20000,
        'custom_amount_step' => 1,
    ]);

    $payload = [[
        'id' => $product->id,
        'quantity' => 5,
        'requested_amount' => 9539,
    ]];

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('checkout', $payload);

    $order = Order::query()->firstOrFail();
    $orderItem = OrderItem::query()->firstOrFail();

    expect($order->status)->toBe(OrderStatus::Paid);
    expect($orderItem->amount_mode)->toBe(ProductAmountMode::Custom);
    expect($orderItem->quantity)->toBe(1);
    expect($orderItem->requested_amount)->toBe(9539);
    expect((float) $orderItem->line_total)->toBe((float) $orderItem->unit_price);
    expect(data_get($orderItem->pricing_meta, 'mode'))->toBe(ProductAmountMode::Custom->value);
    expect(data_get($orderItem->pricing_meta, 'requested_amount'))->toBe(9539);

    $fulfillments = Fulfillment::query()
        ->where('order_item_id', $orderItem->id)
        ->get();

    expect($fulfillments)->toHaveCount(1);
    expect($fulfillments->pluck('status')->unique()->all())->toBe([FulfillmentStatus::Queued]);
    expect($fulfillments->first()->meta)->toBe([
        'type' => 'custom_amount',
        'amount' => 9539,
        'unit' => 'crystals',
    ]);
});

test('custom amount pricing keeps precision for large amount and small entry price', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'name' => 'Precision Crystals',
        'entry_price' => 0.01,
        'amount_mode' => ProductAmountMode::Custom,
        'amount_unit_label' => 'crystals',
        'custom_amount_min' => 1,
        'custom_amount_max' => 200000,
        'custom_amount_step' => 1,
    ]);

    $order = app(\App\Actions\Orders\CreateOrderFromCartPayload::class)->handle($user, [[
        'product_id' => $product->id,
        'quantity' => 99,
        'requested_amount' => 9539,
    ]], null, false);

    $item = $order->items->first();

    expect($item)->not->toBeNull();
    expect($item->quantity)->toBe(1);
    expect($item->requested_amount)->toBe(9539);
    expect(data_get($item->pricing_meta, 'computed_entry_total'))->toBe(95.39);
    expect((float) $item->unit_price)->toBe((float) $item->line_total);
});

test('custom amount hard limit rejects oversized requested amount', function () {
    config()->set('billing.custom_amount_hard_cap', 100000);

    $user = User::factory()->create();
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 1.25,
        'amount_mode' => ProductAmountMode::Custom,
        'custom_amount_min' => 1,
        'custom_amount_max' => 1000000,
        'custom_amount_step' => 1,
    ]);

    expect(fn () => app(\App\Actions\Orders\CreateOrderFromCartPayload::class)->handle($user, [[
        'product_id' => $product->id,
        'requested_amount' => 100001,
    ]], null, false))->toThrow(ValidationException::class);
});

test('custom amount rejects non-positive entry price products', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 0,
        'amount_mode' => ProductAmountMode::Custom,
        'custom_amount_min' => 1,
        'custom_amount_max' => 10000,
        'custom_amount_step' => 1,
    ]);

    expect(fn () => app(\App\Actions\Orders\CreateOrderFromCartPayload::class)->handle($user, [[
        'product_id' => $product->id,
        'requested_amount' => 10,
    ]], null, false))->toThrow(ValidationException::class);
});

test('fixed amount checkout applies pricing rule on total base', function () {
    PricingRule::query()->delete();
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 100,
        'wholesale_percentage' => 0,
        'retail_percentage' => 50,
        'priority' => 0,
        'is_active' => true,
    ]);
    PricingRule::create([
        'min_price' => 100,
        'max_price' => 1000,
        'wholesale_percentage' => 0,
        'retail_percentage' => 10,
        'priority' => 1,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    Wallet::create([
        'user_id' => $user->id,
        'type' => WalletType::Customer,
        'balance' => 1000,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 90,
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('checkout', [[
            'id' => $product->id,
            'quantity' => 2,
        ]]);

    $order = Order::query()->firstOrFail();
    $item = OrderItem::query()->firstOrFail();

    expect((float) $order->subtotal)->toBe(198.0);
    expect((float) $item->line_total)->toBe(198.0);
    expect((float) $item->unit_price)->toBe(99.0);
    expect($item->quantity)->toBe(2);
});

test('fixed amount rule band selection uses total not unit', function () {
    PricingRule::query()->delete();
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 100,
        'wholesale_percentage' => 0,
        'retail_percentage' => 50,
        'priority' => 0,
        'is_active' => true,
    ]);
    PricingRule::create([
        'min_price' => 100,
        'max_price' => 1000,
        'wholesale_percentage' => 0,
        'retail_percentage' => 10,
        'priority' => 1,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    Wallet::create([
        'user_id' => $user->id,
        'type' => WalletType::Customer,
        'balance' => 1000,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 60,
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('checkout', [[
            'id' => $product->id,
            'quantity' => 2,
        ]]);

    $order = Order::query()->firstOrFail();

    expect((float) $order->subtotal)->toBe(132.0)
        ->and((float) $order->subtotal)->not->toBe(180.0);
});

test('repurchase with identical cart after paid idempotency window creates a new order', function () {
    $user = User::factory()->create();
    Wallet::create([
        'user_id' => $user->id,
        'type' => WalletType::Customer,
        'balance' => 1000,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    $payload = [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
    ]];

    $first = app(CheckoutFromPayload::class)->handle($user, $payload);
    $first->order->update(['paid_at' => now()->subMinutes(10)]);

    $second = app(CheckoutFromPayload::class)->handle($user, $payload);

    expect($first->order->id)->not->toBe($second->order->id)
        ->and($second->reusedExistingOrder)->toBeFalse()
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(Fulfillment::query()->count())->toBe(2);
});

test('duplicate checkout within paid idempotency window reuses the same order', function () {
    $user = User::factory()->create();
    Wallet::create([
        'user_id' => $user->id,
        'type' => WalletType::Customer,
        'balance' => 1000,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    $payload = [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
    ]];

    $first = app(CheckoutFromPayload::class)->handle($user, $payload);
    $second = app(CheckoutFromPayload::class)->handle($user, $payload);

    expect($first->order->id)->toBe($second->order->id)
        ->and($second->reusedExistingOrder)->toBeTrue()
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(1);
});

test('repurchase after fulfillment failed without refund creates a new order', function () {
    $user = User::factory()->create();
    Wallet::create([
        'user_id' => $user->id,
        'type' => WalletType::Customer,
        'balance' => 1000,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    $payload = [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
    ]];

    $first = app(CheckoutFromPayload::class)->handle($user, $payload);
    $first->order->update(['paid_at' => now()->subDay()]);

    $fulfillment = Fulfillment::query()->where('order_id', $first->order->id)->firstOrFail();
    app(FailFulfillment::class)->handle($fulfillment, 'Supplier rejected', 'system');

    $second = app(CheckoutFromPayload::class)->handle($user, $payload);

    expect($second->reusedExistingOrder)->toBeFalse()
        ->and($second->order->id)->not->toBe($first->order->id)
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(Fulfillment::query()->where('status', FulfillmentStatus::Queued)->count())->toBe(1);
});
