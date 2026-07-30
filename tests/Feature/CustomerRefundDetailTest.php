<?php

declare(strict_types=1);

use App\Actions\Orders\RefundOrderItem;
use App\Actions\Refunds\ApproveRefundRequest;
use App\Actions\Refunds\GetCustomerRefundDetail;
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
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('shows posted refund as money moved with ledger destination', function (): void {
    $permission = Permission::firstOrCreate(['name' => 'process_refunds', 'guard_name' => 'web']);
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $adminRole->givePermissionTo($permission);
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $user = User::factory()->create();
    Wallet::forUser($user);

    $package = Package::factory()->create();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 15]);
    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 15,
        'fee' => 0,
        'total' => 15,
        'status' => OrderStatus::Paid,
    ]);
    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 15,
        'quantity' => 1,
        'line_total' => 15,
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
    app(ApproveRefundRequest::class)->handle($admin, $tx->id);

    $detail = app(GetCustomerRefundDetail::class)->handle($user, (string) $tx->fresh()->public_ref);

    expect($detail->moneyMoved)->toBeTrue()
        ->and($detail->postedAt)->not->toBeNull()
        ->and($detail->ledgerDestination)->not->toBeNull();

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-refund-detail', ['refund' => $tx->fresh()->public_ref])
        ->assertSee(__('messages.refund_status_refunded'))
        ->assertSeeHtml('data-test="refund-view-ledger"');
});
