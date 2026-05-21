<?php

use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\TopupRequestStatus;
use App\Livewire\Sidebar\FulfillmentIndicator;
use App\Livewire\Sidebar\NotificationIndicator;
use App\Livewire\Sidebar\TopupIndicator;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Date::setTestNow(now());

    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $role->syncPermissions([
        Permission::firstOrCreate(['name' => 'view_fulfillments']),
        Permission::firstOrCreate(['name' => 'manage_topups']),
    ]);

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');
});

test('fulfillment indicator counts queued and processing fulfillments', function () {
    actingAs($this->admin);
    Livewire::actingAs($this->admin);

    $component = Livewire::test(FulfillmentIndicator::class);
    $component->assertSet('count', 0)
        ->assertDontSee('bg-amber-500');

    $productOwner = User::factory()->create();
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 25,
    ]);

    $order = Order::create([
        'user_id' => $productOwner->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 25,
        'fee' => 0,
        'total' => 25,
        'status' => OrderStatus::Paid,
    ]);

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'quantity' => 1,
        'line_total' => 25,
        'unit_price' => 25,
        'status' => OrderItemStatus::Pending,
    ]);

    Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Queued,
    ]);

    $component->call('refreshCount')
        ->assertSet('count', 1)
        ->assertSee('bg-amber-500')
        ->assertSee('1');

    Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
    ]);

    $component->call('refreshCount')
        ->assertSet('count', 1)
        ->assertSee('1');
});

test('topup indicator updates when pending topups exist', function () {
    actingAs($this->admin);
    Livewire::actingAs($this->admin);

    $component = Livewire::test(TopupIndicator::class);
    $component->assertSet('count', 0)
        ->assertDontSee('bg-amber-500');

    $requestOwner = User::factory()->create();

    app(\App\Actions\Topups\CreateTopupRequestAction::class)->handle([
        'user_id' => $requestOwner->id,
        'wallet_id' => null,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 40,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    $component->call('refreshCount')
        ->assertSet('count', 1)
        ->assertSee('bg-amber-500')
        ->assertSee('1');
});

test('notification indicator shows unread notifications count', function () {
    actingAs($this->admin);
    Livewire::actingAs($this->admin);

    $component = Livewire::test(NotificationIndicator::class);
    $component->assertSet('count', 0)
        ->assertDontSee('bg-amber-500');

    $this->admin->notify(new \App\Notifications\PaymentFailedNotification(
        sourceType: User::class,
        sourceId: $this->admin->id,
        title: 'Price floor alert',
        message: 'A line item price was clamped to entry price.',
        url: '/admin/orders'
    ));

    $component->call('refreshCount')
        ->assertSet('count', 1)
        ->assertSee('bg-amber-500')
        ->assertSee('1');
});
