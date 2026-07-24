<?php

use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageRequirement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::firstOrCreate(['name' => 'admin']);
    $role->syncPermissions([
        Permission::firstOrCreate(['name' => 'view_orders']),
    ]);
});

function makeAdminOrder(User $user): Order
{
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 50,
        'fee' => 0,
        'total' => 50,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 50,
        'quantity' => 1,
        'line_total' => 50,
        'status' => OrderItemStatus::Pending,
    ]);

    Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Queued,
        'attempts' => 0,
    ]);

    return $order;
}

test('admin can view orders index and detail', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $user = User::factory()->create();
    $order = makeAdminOrder($user);

    $this->actingAs($admin)
        ->get('/admin/orders')
        ->assertOk()
        ->assertSee($order->order_number);

    $this->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee($order->order_number)
        ->assertSee($user->username);
});

test('admin order detail does not render refund action', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $user = User::factory()->create();
    $order = makeAdminOrder($user);

    $this->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertDontSee(__('messages.refund_order'))
        ->assertDontSee('refundOrder');
});

test('non-admin cannot access admin orders pages', function () {
    $user = User::factory()->create();
    $order = makeAdminOrder($user);

    $this->actingAs($user)
        ->get('/admin/orders')
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('admin.orders.show', $order))
        ->assertNotFound();
});

test('admin order detail shows package requirement labels', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $user = User::factory()->create();
    $package = Package::factory()->create();
    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'reference',
        'label' => 'Order reference code',
        'type' => 'string',
        'is_required' => false,
        'order' => 1,
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 50,
        'fee' => 0,
        'total' => 50,
        'status' => OrderStatus::Paid,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 50,
        'quantity' => 1,
        'line_total' => 50,
        'status' => OrderItemStatus::Pending,
        'requirements_payload' => ['reference' => 'REF-001'],
    ]);

    $this->actingAs($admin)
        ->get(route('admin.orders.show', $order))
        ->assertOk()
        ->assertSee('Order reference code', false)
        ->assertSee('REF-001', false);
});
