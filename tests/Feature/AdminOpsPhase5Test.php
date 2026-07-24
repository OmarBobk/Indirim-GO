<?php

use App\Actions\Dashboard\FormatOpsQueueAge;
use App\Actions\Dashboard\GetAdminDailyStats;
use App\Actions\Dashboard\GetAdminOpsInbox;
use App\Actions\Orders\RefundOrderItem;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\AdminOpsInboxChanged;
use App\Events\FulfillmentListChanged;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Support\AdminOpsBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('format ops queue age returns badge after threshold', function () {
    $formatter = app(FormatOpsQueueAge::class);

    expect($formatter->handle(now()->subHours(2)))->toBeNull()
        ->and($formatter->handle(now()->subHours(6))['severity'])->toBe('zinc')
        ->and($formatter->handle(now()->subDays(2))['severity'])->toBe('amber')
        ->and($formatter->handle(now()->subDays(4))['severity'])->toBe('red');
});

test('ops inbox cards include age badge for old pending refunds', function () {
    Role::firstOrCreate(['name' => 'admin']);
    foreach (['view_dashboard', 'view_refunds', 'view_orders', 'view_fulfillments', 'manage_fulfillments'] as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }
    $role = Role::firstOrCreate(['name' => 'ops-admin']);
    $role->syncPermissions(['view_dashboard', 'view_refunds', 'view_orders', 'view_fulfillments', 'manage_fulfillments']);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $user = User::factory()->create();
    Wallet::forUser($user);
    $package = Package::factory()->create();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 20]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 20,
        'fee' => 0,
        'total' => 20,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 20,
        'quantity' => 1,
        'line_total' => 20,
        'status' => OrderItemStatus::Failed,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 1,
    ]);

    Carbon::setTestNow(now()->subDays(2));
    (new RefundOrderItem)->handle($fulfillment, $user->id);
    Carbon::setTestNow();

    $inbox = app(GetAdminOpsInbox::class)->handle($admin);
    $refundCard = collect($inbox['exception_cards'])->firstWhere('key', 'pending_refunds');

    expect($refundCard['count'])->toBe(1)
        ->and($refundCard['age_label'] ?? null)->not->toBeNull()
        ->and($refundCard['age_severity'])->toBe('amber');
});

test('daily stats include gross margin and fulfillment sla kpis', function () {
    $user = User::factory()->create();
    Wallet::forUser($user);
    $package = Package::factory()->create();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 10]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 15,
        'fee' => 0,
        'total' => 15,
        'status' => OrderStatus::Paid,
        'created_at' => now(),
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 15,
        'entry_price' => 10,
        'quantity' => 1,
        'line_total' => 15,
        'status' => OrderItemStatus::Fulfilled,
        'created_at' => now(),
    ]);

    Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => OrderItem::query()->where('order_id', $order->id)->value('id'),
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 1,
        'created_at' => now()->subMinutes(20),
        'completed_at' => now(),
    ]);

    $stats = app(GetAdminDailyStats::class)->handle('today');
    $keys = collect($stats['kpis'])->pluck('key')->all();

    expect($keys)->toContain('gross_margin', 'fulfillment_sla')
        ->and((float) collect($stats['kpis'])->firstWhere('key', 'gross_margin')['value'])->toBe(33.3)
        ->and((float) collect($stats['kpis'])->firstWhere('key', 'fulfillment_sla')['value'])->toBe(100.0);
});

test('fulfillment list changed triggers admin ops inbox broadcast', function () {
    Event::fake([AdminOpsInboxChanged::class]);

    event(new FulfillmentListChanged(42, 'completed'));

    Event::assertDispatched(AdminOpsInboxChanged::class, function (AdminOpsInboxChanged $event): bool {
        return $event->reason === 'fulfillment:completed';
    });
});

test('admin ops broadcaster dispatches inbox changed event', function () {
    Event::fake([AdminOpsInboxChanged::class]);

    \Illuminate\Support\Facades\DB::transaction(function (): void {
        AdminOpsBroadcaster::dispatch('test');
    });

    Event::assertDispatched(AdminOpsInboxChanged::class, fn (AdminOpsInboxChanged $event): bool => $event->reason === 'test');
});

test('dashboard livewire reloads inbox on admin ops event', function () {
    foreach ([
        'view_dashboard', 'view_orders', 'view_fulfillments', 'manage_fulfillments',
        'view_refunds', 'manage_topups', 'manage_settlements', 'manage_users',
    ] as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }
    $role = Role::firstOrCreate(['name' => 'dash-admin']);
    $role->syncPermissions([
        'view_dashboard', 'view_orders', 'view_fulfillments', 'manage_fulfillments',
        'view_refunds', 'manage_topups', 'manage_settlements', 'manage_users',
    ]);
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $admin->assignRole(Role::firstOrCreate(['name' => 'admin']));

    Livewire::actingAs($admin)
        ->test('pages::backend.dashboard')
        ->call('onOpsInboxUpdated')
        ->assertHasNoErrors()
        ->assertSet('inbox.variant', 'full');
});
