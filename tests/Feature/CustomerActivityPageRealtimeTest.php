<?php

declare(strict_types=1);

use App\Actions\Activity\GetCustomerActivity;
use App\Actions\Orders\PayOrderWithWallet;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\TopupApprovedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function seedUnreadActivityNotification(User $user, string $title = 'Unread item'): DatabaseNotification
{
    return $user->notifications()->create([
        'id' => (string) Str::uuid(),
        'type' => TopupApprovedNotification::class,
        'data' => [
            'source_type' => 'App\\Models\\TopupRequest',
            'source_id' => 1,
            'title' => $title,
            'message' => 'Message',
            'url' => route('wallet'),
            'trace_id' => (string) Str::uuid(),
        ],
    ]);
}

it('refreshes page one activity in place when invalidated', function (): void {
    $user = User::factory()->create();
    seedUnreadActivityNotification($user, 'Before refresh');

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->assertSee('Before refresh');

    seedUnreadActivityNotification($user, 'After refresh');

    $component
        ->dispatch('customer-activity-invalidate', isReconcile: false)
        ->assertSet('hasPendingRefresh', false)
        ->assertSee('After refresh');
});

it('sets pending refresh banner on page two without replacing visible rows', function (): void {
    $user = User::factory()->create();

    for ($index = 1; $index <= 6; $index++) {
        $notification = seedUnreadActivityNotification($user, 'Notification '.$index);
        $notification->forceFill(['created_at' => now()->subMinutes(10 - $index)])->save();
    }

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->set('perPage', 5)
        ->call('gotoPage', 2)
        ->assertSee('Notification 1')
        ->assertSet('hasPendingRefresh', false);

    $newNotification = seedUnreadActivityNotification($user, 'Brand new page one item');
    $newNotification->forceFill(['created_at' => now()->addMinute()])->save();

    $component
        ->dispatch('customer-activity-invalidate', isReconcile: false)
        ->assertSet('hasPendingRefresh', true)
        ->assertSee('Notification 1')
        ->assertDontSee('Brand new page one item');
});

it('returns to page one when pending refresh is applied', function (): void {
    $user = User::factory()->create();

    for ($index = 1; $index <= 6; $index++) {
        $notification = seedUnreadActivityNotification($user, 'Notification '.$index);
        $notification->forceFill(['created_at' => now()->subMinutes(10 - $index)])->save();
    }

    Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->set('perPage', 5)
        ->call('gotoPage', 2)
        ->tap(function () use ($user): void {
            $notification = seedUnreadActivityNotification($user, 'Brand new page one item');
            $notification->forceFill(['created_at' => now()->addMinute()])->save();
        })
        ->dispatch('customer-activity-invalidate', isReconcile: false)
        ->assertSet('hasPendingRefresh', true)
        ->call('applyPendingRefresh')
        ->assertSet('hasPendingRefresh', false)
        ->assertSee('Brand new page one item');
});

it('removes resolved action required items after domain invalidation refresh', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::create([
        'user_id' => $user->id,
        'balance' => 150,
        'currency' => 'USD',
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::PendingPayment,
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 100,
        'quantity' => 1,
        'line_total' => 100,
        'status' => OrderItemStatus::Pending,
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->set('filter', 'action_required')
        ->assertSee(__('messages.activity_action_order_payment_title'));

    app(PayOrderWithWallet::class)->handle($order, $wallet);

    $component
        ->dispatch('customer-activity-invalidate', isReconcile: false, source: 'domain')
        ->assertDontSee(__('messages.activity_action_order_payment_title'));
});

it('keeps unread filter notification-only after domain invalidation', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::create([
        'user_id' => $user->id,
        'balance' => 150,
        'currency' => 'USD',
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::PendingPayment,
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 100]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 100,
        'quantity' => 1,
        'line_total' => 100,
        'status' => OrderItemStatus::Pending,
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->set('filter', 'unread')
        ->assertSee(__('messages.activity_empty_unread_title'))
        ->dispatch('customer-activity-invalidate', isReconcile: false, source: 'domain')
        ->assertSee(__('messages.activity_empty_unread_title'))
        ->assertDontSee(__('messages.activity_action_order_payment_title'));
});

it('preserves selected filters across invalidation refresh', function (): void {
    $user = User::factory()->create();
    seedUnreadActivityNotification($user, 'Orders only');

    Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->set('filter', 'unread')
        ->set('category', 'orders')
        ->dispatch('customer-activity-invalidate', isReconcile: false)
        ->assertSet('filter', 'unread')
        ->assertSet('category', 'orders');
});

it('does not move focus when invalidated', function (): void {
    $user = User::factory()->create();
    seedUnreadActivityNotification($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.activity')
        ->dispatch('customer-activity-invalidate', isReconcile: false)
        ->assertSee('data-test="activity-page"', false)
        ->assertDontSee('autofocus', false);
});

it('deduplicates activity rows by stable keys after refresh', function (): void {
    $user = User::factory()->create();
    $result = app(GetCustomerActivity::class)->handle($user);
    $keys = array_map(fn ($item) => $item->stableKey, $result->items);

    expect($keys)->toBe(array_values(array_unique($keys)));
});
