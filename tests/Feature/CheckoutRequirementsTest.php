<?php

use App\Actions\Orders\CheckoutFromPayload;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\Package;
use App\Models\PackageRequirement;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function makeProductWithRequirement(): array
{
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 15,
    ]);

    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'id',
        'label' => 'ID',
        'type' => 'string',
        'is_required' => true,
        'validation_rules' => 'required|string',
        'order' => 1,
    ]);

    return compact('package', 'product');
}

test('checkout fails when requirements are missing (single item)', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => 100]);

    ['package' => $package, 'product' => $product] = makeProductWithRequirement();

    $action = app(CheckoutFromPayload::class);

    expect(fn () => $action->handle($user, [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
        'requirements' => [],
    ]]))->toThrow(ValidationException::class);
});

test('cart checkout shows error and does not create order when requirements missing', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => 100]);

    ['package' => $package, 'product' => $product] = makeProductWithRequirement();

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('checkout', [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'requirements' => [],
        ]])
        ->assertSet('checkoutSuccess', null);

    expect(Order::query()->count())->toBe(0);
});

test('checkout stores requirements and creates fulfillment', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => 100]);

    ['package' => $package, 'product' => $product] = makeProductWithRequirement();

    $action = app(CheckoutFromPayload::class);

    $order = $action->handle($user, [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
        'requirements' => [
            'id' => '12345',
        ],
    ]])->order;

    $order->refresh();
    $item = $order->items()->first();

    expect($order->status)->toBe(OrderStatus::Paid);
    expect($item)->not->toBeNull();
    expect($item->requirements_payload)->toMatchArray(['id' => '12345']);
    expect(Fulfillment::query()->where('order_id', $order->id)->exists())->toBeTrue();
});

test('number requirements enforce numeric min value', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => 100]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 15,
    ]);

    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'id',
        'label' => 'ID',
        'type' => 'number',
        'is_required' => true,
        'validation_rules' => 'min:5',
        'order' => 1,
    ]);

    $action = app(CheckoutFromPayload::class);

    expect(fn () => $action->handle($user, [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
        'requirements' => [
            'id' => 3,
        ],
    ]]))->toThrow(ValidationException::class);

    $order = $action->handle($user, [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
        'requirements' => [
            'id' => 5,
        ],
    ]])->order;

    expect($order->status)->toBe(OrderStatus::Paid);
});
