<?php

use App\Actions\Fortify\CreateNewUser;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\Commission;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Notifications\SalespersonPayoutRequestedNotification;
use App\Services\SalespersonDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('salesperson dashboard requires sales permission', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals']);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('salesperson.dashboard'))
        ->assertNotFound();

    $user->givePermissionTo('view_referrals');

    $this->actingAs($user)
        ->get(route('salesperson.dashboard'))
        ->assertSuccessful()
        ->assertSee(__('messages.salesperson_dashboard'));
});

test('newly registered user is linked to referrer from referral cookie', function () {
    $referrer = User::factory()->create();

    $cookieName = (string) config('referral.cookie_name', 'karman_ref');
    $request = Request::create('/', 'POST', [], [$cookieName => $referrer->referral_code]);
    $this->app->instance('request', $request);

    $user = app(CreateNewUser::class)->create([
        'name' => 'Referred User',
        'username' => 'referreduser',
        'email' => 'referred@example.com',
        'preferred_currency' => 'USD',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'country_code' => '90',
        'timezone_detected' => 'Europe/Istanbul',
    ]);

    expect($user->referred_by_user_id)->toBe($referrer->id);
});

test('salesperson dashboard renders live commission order data', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals']);

    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');
    $customer = User::factory()->create();

    $order = Order::query()->create([
        'user_id' => $customer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 150,
        'fee' => 0,
        'total' => 150,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    Commission::query()->create([
        'order_id' => $order->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'REFLIVE1',
        'order_total' => 150,
        'commission_amount' => 30,
        'commission_rate_percent' => 20,
        'status' => CommissionStatus::Pending,
    ]);

    $this->actingAs($salesperson)
        ->get(route('salesperson.dashboard'))
        ->assertSuccessful()
        ->assertSee($order->order_number)
        ->assertSee('$30.00', false);
});

test('salesperson chart preset series includes all fixed ranges', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals']);

    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');

    $presets = app(SalespersonDashboardService::class)->buildChartPresetSeries((int) $salesperson->id);

    expect($presets)->toHaveKeys(['today', '7d', 'this_month', 'ytd']);
    expect($presets['today'])->toHaveKeys(['labels', 'earnings', 'orders', 'pending', 'rangeLabel', 'sparkline']);
});

test('request payout does not notify admins when eligible is at or below minimum', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');
    $customer = User::factory()->create();

    $order = Order::query()->create([
        'user_id' => $customer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 50,
        'fee' => 0,
        'total' => 50,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Item',
        'unit_price' => 50,
        'entry_price' => 40,
        'quantity' => 1,
        'line_total' => 50,
    ]);
    Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 0,
    ]);
    $fulfillment = Fulfillment::query()->where('order_item_id', $orderItem->id)->firstOrFail();

    Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'LOW1',
        'order_total' => 50,
        'commission_amount' => 5,
        'commission_rate_percent' => 10,
        'status' => CommissionStatus::Pending,
    ]);

    Notification::fake();

    Livewire::actingAs($salesperson)
        ->test('pages::backend.salesperson-dashboard')
        ->call('requestPayout');

    Notification::assertNothingSent();
});

test('request payout notifies admins when eligible exceeds minimum', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');
    $customer = User::factory()->create();

    $order = Order::query()->create([
        'user_id' => $customer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 150,
        'fee' => 0,
        'total' => 150,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Item',
        'unit_price' => 150,
        'entry_price' => 100,
        'quantity' => 1,
        'line_total' => 150,
    ]);
    Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 0,
    ]);
    $fulfillment = Fulfillment::query()->where('order_item_id', $orderItem->id)->firstOrFail();

    Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'HIGH1',
        'order_total' => 150,
        'commission_amount' => 20,
        'commission_rate_percent' => 13.33,
        'status' => CommissionStatus::Pending,
    ]);

    Notification::fake();

    Livewire::actingAs($salesperson)
        ->test('pages::backend.salesperson-dashboard')
        ->call('requestPayout');

    Notification::assertSentTo($admin, SalespersonPayoutRequestedNotification::class);

    expect(PayoutRequest::query()->where('user_id', $salesperson->id)->where('status', 'pending')->count())->toBe(1);
});

test('request payout does not duplicate pending row or re-notify admins', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');
    $customer = User::factory()->create();

    $order = Order::query()->create([
        'user_id' => $customer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 150,
        'fee' => 0,
        'total' => 150,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Item',
        'unit_price' => 150,
        'entry_price' => 100,
        'quantity' => 1,
        'line_total' => 150,
    ]);
    Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 0,
    ]);
    $fulfillment = Fulfillment::query()->where('order_item_id', $orderItem->id)->firstOrFail();

    Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'DEDUP1',
        'order_total' => 150,
        'commission_amount' => 20,
        'commission_rate_percent' => 13.33,
        'status' => CommissionStatus::Pending,
    ]);

    Notification::fake();

    $component = Livewire::actingAs($salesperson)
        ->test('pages::backend.salesperson-dashboard');

    $component->call('requestPayout');
    $component->call('requestPayout');

    Notification::assertSentToTimes($admin, SalespersonPayoutRequestedNotification::class, 1);
    expect(PayoutRequest::query()->where('user_id', $salesperson->id)->count())->toBe(1);
});

test('admin can view salesperson dashboard as another user with referral access', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'manage_users', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $admin->givePermissionTo(['view_referrals', 'manage_users']);

    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');
    $customer = User::factory()->create();

    $order = Order::query()->create([
        'user_id' => $customer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 150,
        'fee' => 0,
        'total' => 150,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    Commission::query()->create([
        'order_id' => $order->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $customer->id,
        'referral_code' => 'ADMVIEW1',
        'order_total' => 150,
        'commission_amount' => 12,
        'commission_rate_percent' => 8,
        'status' => CommissionStatus::Pending,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::backend.salesperson-dashboard', ['as' => (string) $salesperson->id])
        ->assertSuccessful()
        ->assertSee($order->order_number, false)
        ->assertSee($salesperson->name, false);
});

test('salesperson without manage_users cannot use view-as parameter', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);

    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');

    $other = User::factory()->create();
    $other->givePermissionTo('view_referrals');
    $customer = User::factory()->create();

    $order = Order::query()->create([
        'user_id' => $customer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 200,
        'fee' => 0,
        'total' => 200,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(2),
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => null,
        'salesperson_id' => $other->id,
        'customer_id' => $customer->id,
        'referral_code' => 'OTHERONLY',
        'order_total' => 200,
        'commission_amount' => 20,
        'commission_rate_percent' => 10,
        'status' => CommissionStatus::Pending,
    ]);

    Livewire::actingAs($salesperson)
        ->test('pages::backend.salesperson-dashboard', ['as' => (string) $other->id])
        ->assertSuccessful()
        ->assertDontSee($order->order_number, false);
});

test('salesperson dashboard lists all referred users including those without commissions', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);
    Permission::query()->firstOrCreate(['name' => 'manage_referred_users', 'guard_name' => 'web']);
    Role::query()->firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);

    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $referred = User::factory()->create([
        'name' => 'ReferredNoOrdersYet',
        'referred_by_user_id' => $salesperson->id,
    ]);
    $referred->assignRole('customer');

    $this->actingAs($salesperson)
        ->get(route('salesperson.dashboard'))
        ->assertSuccessful()
        ->assertSee('ReferredNoOrdersYet', false);
});
