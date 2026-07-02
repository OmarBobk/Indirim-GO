<?php

use App\Actions\Dashboard\GetAdminOpsInbox;
use App\Actions\Dashboard\ResolveAdminDashboardVariant;
use App\Actions\Orders\RefundOrderItem;
use App\Enums\AdminDashboardVariant;
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
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, order: Order, fulfillment: Fulfillment}
 */
function makeAdminOpsFixture(): array
{
    $user = User::factory()->create();
    Wallet::forUser($user);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 25,
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

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 25,
        'quantity' => 1,
        'line_total' => 25,
        'status' => OrderItemStatus::Failed,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 1,
        'last_error' => 'Supplier timeout',
    ]);

    return compact('user', 'order', 'fulfillment');
}

/**
 * @param  list<string>  $permissions
 */
function createBackendUserWithPermissions(array $permissions, string $roleName = 'test-role'): User
{
    foreach ($permissions as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }

    $role = Role::firstOrCreate(['name' => $roleName]);
    $role->syncPermissions($permissions);

    $user = User::factory()->create();
    $user->assignRole($role);

    return $user;
}

function createAdminWithDashboardAccess(): User
{
    return createBackendUserWithPermissions([
        'view_dashboard',
        'view_refunds',
        'process_refunds',
        'manage_topups',
        'view_fulfillments',
        'manage_fulfillments',
        'manage_settlements',
        'view_orders',
        'manage_bugs',
        'manage_users',
    ], 'admin');
}

test('admin ops dashboard renders inbox sections', function () {
    $admin = createAdminWithDashboardAccess();

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-test="admin-ops-dashboard"', false)
        ->assertSee('data-variant="full"', false)
        ->assertSee(__('messages.admin_ops_intro'), false)
        ->assertSee(__('messages.admin_ops_all_clear_full'), false);
});

test('admin ops dashboard shows exception counts and recent work', function () {
    $admin = createAdminWithDashboardAccess();
    $fixture = makeAdminOpsFixture();

    (new RefundOrderItem)->handle($fixture['fulfillment'], $fixture['user']->id);

    $this->actingAs($admin)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-test="ops-card-pending_refunds"', false)
        ->assertSee('data-test="ops-card-failed_fulfillments"', false)
        ->assertSee('data-test="queue-health-panel"', false)
        ->assertSee('data-test="recent-pending-refunds"', false)
        ->assertSee('data-test="recent-attention-orders"', false)
        ->assertSee($fixture['order']->order_number, false)
        ->assertDontSee(__('messages.admin_ops_all_clear'), false);
});

test('supervisor dashboard uses orders variant', function () {
    $supervisor = createBackendUserWithPermissions([
        'view_dashboard',
        'view_referrals',
        'view_orders',
        'create_orders',
    ], 'supervisor');

    $fixture = makeAdminOpsFixture();

    $this->actingAs($supervisor)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-variant="orders"', false)
        ->assertSee(__('messages.admin_ops_intro_orders'), false)
        ->assertSee('data-test="ops-card-orders_with_failures"', false)
        ->assertSee('data-test="recent-orders"', false)
        ->assertDontSee('data-test="ops-card-pending_refunds"', false)
        ->assertDontSee('data-test="queue-health-panel"', false)
        ->assertSee($fixture['order']->order_number, false);
});

test('finance only user gets finance variant inbox', function () {
    $financeUser = createBackendUserWithPermissions([
        'view_dashboard',
        'manage_topups',
        'view_refunds',
        'manage_settlements',
    ], 'finance');

    $inbox = app(GetAdminOpsInbox::class)->handle($financeUser);

    expect($inbox['variant'])->toBe(AdminDashboardVariant::Finance->value)
        ->and(collect($inbox['exception_cards'])->pluck('key')->all())->toEqual([
            'pending_refunds',
            'pending_topups',
            'pending_payouts',
        ]);
});

test('fulfillment only user gets fulfillment variant inbox', function () {
    $fulfillmentUser = createBackendUserWithPermissions([
        'view_dashboard',
        'view_fulfillments',
        'manage_fulfillments',
        'view_orders',
    ], 'fulfillment-ops');

    $inbox = app(GetAdminOpsInbox::class)->handle($fulfillmentUser);

    expect($inbox['variant'])->toBe(AdminDashboardVariant::Fulfillment->value)
        ->and(collect($inbox['exception_cards'])->pluck('key')->all())->toEqual([
            'fulfillment_queue',
            'failed_fulfillments',
        ])
        ->and($inbox['queue_health'])->not->toBeNull();
});

test('resolve admin dashboard variant maps roles to expected views', function () {
    $resolver = app(ResolveAdminDashboardVariant::class);

    expect($resolver->handle(createAdminWithDashboardAccess()))->toBe(AdminDashboardVariant::Full);

    expect($resolver->handle(createBackendUserWithPermissions([
        'view_dashboard',
        'view_orders',
        'create_orders',
    ], 'orders-only')))->toBe(AdminDashboardVariant::Orders);

    expect($resolver->handle(createBackendUserWithPermissions([
        'view_dashboard',
        'manage_topups',
        'view_refunds',
    ], 'finance-only')))->toBe(AdminDashboardVariant::Finance);

    expect($resolver->handle(createBackendUserWithPermissions([
        'view_dashboard',
        'view_fulfillments',
        'view_orders',
    ], 'fulfillment-only')))->toBe(AdminDashboardVariant::Fulfillment);
});

test('get admin ops inbox returns permission scoped cards', function () {
    $admin = createAdminWithDashboardAccess();
    $fixture = makeAdminOpsFixture();
    (new RefundOrderItem)->handle($fixture['fulfillment'], $fixture['user']->id);

    $inbox = app(GetAdminOpsInbox::class)->handle($admin);

    expect($inbox['all_clear'])->toBeFalse()
        ->and($inbox['variant'])->toBe('full')
        ->and(collect($inbox['exception_cards'])->pluck('key'))->toContain('pending_refunds', 'failed_fulfillments')
        ->and($inbox['queue_health'])->not->toBeNull()
        ->and($inbox['recent_pending_refunds'])->toHaveCount(1)
        ->and($inbox['recent_attention_orders'])->toHaveCount(1)
        ->and($inbox['recent_attention_orders'][0]['order_number'])->toBe($fixture['order']->order_number);
});

test('get admin ops inbox reports all clear when no exceptions', function () {
    $admin = createAdminWithDashboardAccess();

    $inbox = app(GetAdminOpsInbox::class)->handle($admin);

    expect($inbox['all_clear'])->toBeTrue()
        ->and($inbox['actionable_exception_total'])->toBe(0)
        ->and(collect($inbox['exception_cards'])->every(fn (array $card): bool => $card['count'] === 0))->toBeTrue()
        ->and($inbox['recent_pending_refunds'])->toBe([])
        ->and($inbox['recent_attention_orders'])->toBe([]);
});

test('failed fulfillments alone do not count as actionable exceptions', function () {
    $admin = createAdminWithDashboardAccess();
    makeAdminOpsFixture();

    $inbox = app(GetAdminOpsInbox::class)->handle($admin);
    $failedCard = collect($inbox['exception_cards'])->firstWhere('key', 'failed_fulfillments');

    expect($failedCard['count'])->toBe(1)
        ->and($inbox['actionable_exception_total'])->toBe(0)
        ->and($inbox['all_clear'])->toBeTrue();

    $sidebarCounts = app(\App\Actions\Dashboard\GetAdminSidebarCounts::class)->handle($admin);

    expect($sidebarCounts['total_exceptions'])->toBe(0)
        ->and($sidebarCounts['failed_fulfillments'])->toBe(1);
});

test('pending refund appears in recent table with wallet owner name', function () {
    $admin = createAdminWithDashboardAccess();
    $fixture = makeAdminOpsFixture();
    (new RefundOrderItem)->handle($fixture['fulfillment'], $fixture['user']->id);

    $inbox = app(GetAdminOpsInbox::class)->handle($admin);

    expect($inbox['recent_pending_refunds'][0]['user_name'])->toBe($fixture['user']->name)
        ->and($inbox['recent_pending_refunds'][0]['amount'])->toBe(25.0);

    expect(
        WalletTransaction::query()
            ->where('status', WalletTransaction::STATUS_PENDING)
            ->count()
    )->toBe(1);
});

test('supervisor dashboard shows salesperson cta when referrals allowed', function () {
    $supervisor = createBackendUserWithPermissions([
        'view_dashboard',
        'view_referrals',
        'view_orders',
        'create_orders',
    ], 'supervisor');

    $this->actingAs($supervisor)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee('data-test="supervisor-sales-dashboard-cta"', false)
        ->assertSee(__('messages.admin_ops_salesperson_dashboard_cta'), false);
});

test('finance variant shows finance specific all clear message', function () {
    $financeUser = createBackendUserWithPermissions([
        'view_dashboard',
        'manage_topups',
        'view_refunds',
        'manage_settlements',
    ], 'finance');

    $this->actingAs($financeUser)
        ->get('/dashboard')
        ->assertOk()
        ->assertSee(__('messages.admin_ops_all_clear_finance'), false);
});

test('dashboard refresh busts stats cache and reloads inbox', function () {
    $admin = createAdminWithDashboardAccess();

    $component = Livewire::actingAs($admin)
        ->test('pages::backend.dashboard')
        ->assertSet('statsRange', '7d');

    Cache::put('admin_daily_stats_v1_7d', ['orders' => 999], now()->addMinute());

    $component
        ->call('refreshDashboard')
        ->assertHasNoErrors();

    expect(Cache::get('admin_daily_stats_v1_7d'))->toHaveKey('kpis');
});
