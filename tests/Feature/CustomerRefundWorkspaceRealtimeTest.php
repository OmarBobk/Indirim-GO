<?php

declare(strict_types=1);

use App\Actions\Orders\RefundOrderItem;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('refreshes refunds page one on financial invalidate', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user);

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.wallet-refunds')
        ->assertDontSee('WTX-');

    $package = Package::factory()->create();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 12]);
    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 12,
        'fee' => 0,
        'total' => 12,
        'status' => OrderStatus::Paid,
    ]);
    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 12,
        'quantity' => 1,
        'line_total' => 12,
        'status' => OrderItemStatus::Failed,
    ]);
    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 0,
        'last_error' => 'fail',
    ]);

    $tx = app(RefundOrderItem::class)->handle($fulfillment, $user->id);

    $component
        ->dispatch(
            'customer-financial-invalidate',
            reasons: [CustomerFinancialInvalidationReason::RefundStateChanged->value],
        )
        ->assertSee($tx->public_ref);
});
