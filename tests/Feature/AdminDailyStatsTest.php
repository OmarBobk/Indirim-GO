<?php

use App\Actions\Dashboard\GetAdminDailyStats;
use App\Enums\OrderStatus;
use App\Enums\TopupRequestStatus;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('admin daily stats returns cached kpis and chart series', function () {
    Cache::flush();

    $user = User::factory()->create();
    Wallet::forUser($user);

    Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 50,
        'fee' => 0,
        'total' => 50,
        'status' => OrderStatus::Paid,
        'created_at' => now(),
    ]);

    $stats = app(GetAdminDailyStats::class)->handle('today');

    expect($stats['range'])->toBe('today')
        ->and($stats['kpis'])->toHaveCount(6)
        ->and(collect($stats['kpis'])->firstWhere('key', 'orders')['value'])->toBe(1)
        ->and((float) collect($stats['kpis'])->firstWhere('key', 'revenue')['value'])->toBe(50.0)
        ->and($stats['chart']['labels'])->not->toBeEmpty()
        ->and($stats['chart']['orders'])->toBeArray();
});

test('full admin dashboard shows daily stats panel', function () {
    $permissions = [
        'view_dashboard', 'manage_users', 'view_orders', 'view_fulfillments', 'manage_fulfillments',
    ];
    foreach ($permissions as $permission) {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $permission]);
    }
    $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
    $role->syncPermissions($permissions);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-test="admin-daily-stats"', false)
        ->assertSee('data-test="admin-stat-orders"', false)
        ->assertSee(__('messages.admin_stats_title'), false);
});

test('topups page respects statusFilter query string', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage_topups']);
    $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
    $role->givePermissionTo('manage_topups');
    $admin = User::factory()->create();
    $admin->assignRole($role);

    Livewire::actingAs($admin)
        ->withQueryParams(['statusFilter' => TopupRequestStatus::Pending->value])
        ->test('pages::backend.topups.index')
        ->assertSet('statusFilter', TopupRequestStatus::Pending->value);
});

test('orders page respects fulfillmentFilter query string', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view_orders']);
    $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
    $role->givePermissionTo('view_orders');
    $admin = User::factory()->create();
    $admin->assignRole($role);

    Livewire::actingAs($admin)
        ->withQueryParams(['fulfillmentFilter' => 'failed'])
        ->test('pages::backend.orders.index')
        ->assertSet('fulfillmentFilter', 'failed');
});

test('payout requests page respects statusFilter query string', function () {
    \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage_settlements']);
    $role = \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
    $role->givePermissionTo('manage_settlements');
    $admin = User::factory()->create();
    $admin->assignRole($role);

    Livewire::actingAs($admin)
        ->withQueryParams(['statusFilter' => 'pending'])
        ->test(\App\Livewire\Admin\PayoutRequestsTable::class)
        ->assertSet('statusFilter', 'pending');
});
