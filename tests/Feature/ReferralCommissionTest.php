<?php

use App\Actions\Commissions\CreatePayoutBatch;
use App\Actions\Orders\CheckoutFromPayload;
use App\Actions\Orders\PayOrderWithWallet;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletType;
use App\Livewire\Admin\CommissionsTable;
use App\Models\Commission;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PayoutBatch;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebsiteSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function bindRequestWithCookies(array $cookies): void
{
    $symfonyRequest = \Symfony\Component\HttpFoundation\Request::create('/', 'GET', [], $cookies);
    app()->instance('request', Request::createFromBase($symfonyRequest));
}

test('checkout attaches referral meta from buyer referred_by when no cookie and pay creates commission', function () {
    Permission::query()->firstOrCreate(['name' => 'view_referrals']);
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo('view_referrals');
    $salesperson->refresh();

    $buyer = User::factory()->create([
        'referred_by_user_id' => $salesperson->id,
    ]);

    Wallet::create([
        'user_id' => $buyer->id,
        'type' => WalletType::Customer,
        'balance' => 500,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 100,
    ]);

    bindRequestWithCookies([]);

    $order = app(CheckoutFromPayload::class)->handle($buyer, [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
    ]], []);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid);
    expect(strtoupper((string) data_get($order->meta, 'referral.code')))->toBe(strtoupper((string) $salesperson->referral_code));
    expect((int) data_get($order->meta, 'referral.salesperson_id'))->toBe($salesperson->id);

    $commission = Commission::query()->where('order_id', $order->id)->first();
    expect($commission)->not->toBeNull();
    expect($commission->salesperson_id)->toBe($salesperson->id);
    expect($commission->customer_id)->toBe($buyer->id);
});

test('checkout attaches referral meta from cookie and pay creates commission', function () {
    $referrer = User::factory()->create();
    $buyer = User::factory()->create();
    $referrer->refresh();

    Wallet::create([
        'user_id' => $buyer->id,
        'type' => WalletType::Customer,
        'balance' => 500,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 100,
    ]);

    bindRequestWithCookies([
        (string) config('referral.cookie_name') => (string) $referrer->referral_code,
    ]);

    $order = app(CheckoutFromPayload::class)->handle($buyer, [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
    ]], []);

    $order->refresh();

    expect($order->status)->toBe(OrderStatus::Paid);
    expect(data_get($order->meta, 'referral.code'))->toBe($referrer->referral_code);
    expect((int) data_get($order->meta, 'referral.salesperson_id'))->toBe($referrer->id);

    $commission = Commission::query()->where('order_id', $order->id)->first();
    expect($commission)->not->toBeNull();
    expect($commission->salesperson_id)->toBe($referrer->id);
    expect($commission->customer_id)->toBe($buyer->id);
    expect($commission->status)->toBe(CommissionStatus::Pending);
    expect((float) $commission->commission_amount)->toBe(20.0);
    expect((float) $commission->commission_rate_percent)->toBe(20.0);
});

test('commission uses website default rate when salesperson has no custom rate', function () {
    WebsiteSetting::instance()->update(['default_commission_rate_percent' => 22.5]);

    $referrer = User::factory()->create();
    $buyer = User::factory()->create();
    $referrer->refresh();

    Wallet::create([
        'user_id' => $buyer->id,
        'type' => WalletType::Customer,
        'balance' => 500,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 100,
    ]);

    bindRequestWithCookies([
        (string) config('referral.cookie_name') => (string) $referrer->referral_code,
    ]);

    $order = app(CheckoutFromPayload::class)->handle($buyer, [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
    ]], []);

    $commission = Commission::query()->where('order_id', $order->id)->first();
    expect($commission)->not->toBeNull();
    expect((float) $commission->commission_rate_percent)->toBe(22.5);
    expect((float) $commission->commission_amount)->toBe(22.5);
});

test('commission uses salesperson custom rate percent when set', function () {
    $referrer = User::factory()->create([
        'commission_rate_percent' => 35.00,
    ]);
    $buyer = User::factory()->create();
    $referrer->refresh();

    Wallet::create([
        'user_id' => $buyer->id,
        'type' => WalletType::Customer,
        'balance' => 500,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 100,
    ]);

    bindRequestWithCookies([
        (string) config('referral.cookie_name') => (string) $referrer->referral_code,
    ]);

    $order = app(CheckoutFromPayload::class)->handle($buyer, [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
    ]], []);

    $commission = Commission::query()->where('order_id', $order->id)->first();
    expect($commission)->not->toBeNull();
    expect((float) $commission->commission_rate_percent)->toBe(35.0);
    expect((float) $commission->commission_amount)->toBe(35.0);
});

test('checkout does not attach referral when cookie matches buyer self', function () {
    $buyer = User::factory()->create();
    $buyer->refresh();

    Wallet::create([
        'user_id' => $buyer->id,
        'type' => WalletType::Customer,
        'balance' => 500,
        'currency' => 'USD',
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    bindRequestWithCookies([
        (string) config('referral.cookie_name') => (string) $buyer->referral_code,
    ]);

    $order = app(CheckoutFromPayload::class)->handle($buyer, [[
        'product_id' => $product->id,
        'package_id' => $package->id,
        'quantity' => 1,
    ]], []);

    $order->refresh();

    expect(data_get($order->meta, 'referral'))->toBeNull();
    expect(Commission::query()->where('order_id', $order->id)->exists())->toBeFalse();
});

test('pay order does not create commission for self referral in meta', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => 200]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::PendingPayment,
        'meta' => [
            'referral' => [
                'code' => 'SELFREF1',
                'salesperson_id' => $user->id,
            ],
        ],
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    app(PayOrderWithWallet::class)->handle($order, $wallet);

    expect(Commission::query()->where('order_id', $order->id)->exists())->toBeFalse();
});

test('admin can mark commission as credited', function () {
    Permission::firstOrCreate(['name' => 'manage_settlements']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage_settlements');

    $referrer = User::factory()->create();
    $buyer = User::factory()->create();

    $order = Order::create([
        'user_id' => $buyer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
        'meta' => ['referral' => ['code' => 'X', 'salesperson_id' => $referrer->id]],
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Sample Item',
        'unit_price' => 100,
        'entry_price' => 80,
        'quantity' => 1,
        'line_total' => 100,
    ]);
    Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 0,
    ]);
    $fulfillment = Fulfillment::query()->where('order_item_id', $orderItem->id)->firstOrFail();

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $referrer->id,
        'customer_id' => $buyer->id,
        'referral_code' => 'REFCODE1',
        'order_total' => 100,
        'commission_amount' => 20,
        'status' => CommissionStatus::Pending,
        'paid_at' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(CommissionsTable::class)
        ->call('markPaid', $commission->id);

    $commission->refresh();
    expect($commission->status)->toBe(CommissionStatus::Credited);
    expect($commission->paid_at)->not->toBeNull();
    expect($commission->wallet_transaction_id)->not->toBeNull();

    $salespersonWallet = Wallet::query()->where('user_id', $referrer->id)->firstOrFail();
    expect(Activity::query()
        ->where('event', 'commission.credited')
        ->where('log_name', 'payments')
        ->where('subject_type', Commission::class)
        ->where('subject_id', $commission->id)
        ->exists()
    )->toBeTrue();
    expect(Activity::query()
        ->where('event', 'wallet.credited')
        ->where('log_name', 'payments')
        ->where('subject_type', Wallet::class)
        ->where('subject_id', $salespersonWallet->id)
        ->exists()
    )->toBeTrue();
});

test('admin cannot mark commission as credited when fulfillments are not completed', function () {
    Permission::firstOrCreate(['name' => 'manage_settlements']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage_settlements');

    $referrer = User::factory()->create();
    $buyer = User::factory()->create();

    $order = Order::create([
        'user_id' => $buyer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
        'meta' => ['referral' => ['code' => 'X', 'salesperson_id' => $referrer->id]],
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Sample Item',
        'unit_price' => 100,
        'entry_price' => 80,
        'quantity' => 1,
        'line_total' => 100,
    ]);
    Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Queued,
        'attempts' => 0,
    ]);
    $fulfillment = Fulfillment::query()->where('order_item_id', $orderItem->id)->firstOrFail();

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $referrer->id,
        'customer_id' => $buyer->id,
        'referral_code' => 'REFCODE1',
        'order_total' => 100,
        'commission_amount' => 20,
        'status' => CommissionStatus::Pending,
        'paid_at' => null,
    ]);

    Livewire::actingAs($admin)
        ->test(CommissionsTable::class)
        ->call('markPaid', $commission->id);

    $commission->refresh();
    expect($commission->status)->toBe(CommissionStatus::Pending);
    expect($commission->paid_at)->toBeNull();
});

test('admin can create payout batch for eligible commissions', function () {
    Permission::firstOrCreate(['name' => 'manage_settlements']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage_settlements');

    $salesperson = User::factory()->create();
    $buyer = User::factory()->create();

    $order = Order::create([
        'user_id' => $buyer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 1500,
        'fee' => 0,
        'total' => 1500,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
        'meta' => ['referral' => ['code' => 'X', 'salesperson_id' => $salesperson->id]],
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    $orderItemA = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Item A',
        'unit_price' => 1000,
        'entry_price' => 700,
        'quantity' => 1,
        'line_total' => 1000,
    ]);
    $fulfillmentA = Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItemA->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 0,
    ]);

    $orderItemB = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Item B',
        'unit_price' => 500,
        'entry_price' => 350,
        'quantity' => 1,
        'line_total' => 500,
    ]);
    $fulfillmentB = Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItemB->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 0,
    ]);

    $commissionA = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillmentA->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $buyer->id,
        'referral_code' => 'REFA0001',
        'order_total' => 1000,
        'commission_amount' => 120,
        'status' => CommissionStatus::Pending,
    ]);

    $commissionB = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillmentB->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $buyer->id,
        'referral_code' => 'REFB0001',
        'order_total' => 500,
        'commission_amount' => 90,
        'status' => CommissionStatus::Pending,
    ]);

    $this->actingAs($admin);
    app(CreatePayoutBatch::class)->handle([$commissionA->id, $commissionB->id], 'manual payout');

    $batch = PayoutBatch::query()->first();
    expect($batch)->not->toBeNull();
    expect((float) $batch->total_amount)->toBe(210.0);
    expect($batch->commission_count)->toBe(2);

    $commissionA->refresh();
    $commissionB->refresh();
    expect($commissionA->status)->toBe(CommissionStatus::Credited);
    expect($commissionB->status)->toBe(CommissionStatus::Credited);
    expect($commissionA->payout_batch_id)->toBe($batch->id);
    expect($commissionB->payout_batch_id)->toBe($batch->id);
    expect($commissionA->wallet_transaction_id)->not->toBeNull();
    expect($commissionB->wallet_transaction_id)->not->toBeNull();

    expect(Activity::query()
        ->where('event', 'commission.credited')
        ->where('log_name', 'payments')
        ->count()
    )->toBe(2);
    expect(Activity::query()
        ->where('event', 'wallet.credited')
        ->where('log_name', 'payments')
        ->count()
    )->toBe(2);
});

test('admin payout batch requires minimum eligible amount', function () {
    Permission::firstOrCreate(['name' => 'manage_settlements']);
    $admin = User::factory()->create();
    $admin->givePermissionTo('manage_settlements');

    $salesperson = User::factory()->create();
    $buyer = User::factory()->create();

    $order = Order::create([
        'user_id' => $buyer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
        'meta' => ['referral' => ['code' => 'X', 'salesperson_id' => $salesperson->id]],
    ]);
    $order->order_number = Order::generateOrderNumber($order->id, (int) $order->created_at?->format('Y'));
    $order->save();

    $orderItem = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Item A',
        'unit_price' => 100,
        'entry_price' => 80,
        'quantity' => 1,
        'line_total' => 100,
    ]);
    $fulfillment = Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
        'attempts' => 0,
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $buyer->id,
        'referral_code' => 'REFMIN01',
        'order_total' => 100,
        'commission_amount' => 50,
        'status' => CommissionStatus::Pending,
    ]);

    Livewire::actingAs($admin)
        ->test(CommissionsTable::class)
        ->set('selectedCommissionIds', [$commission->id])
        ->call('createPayout');

    expect(PayoutBatch::query()->exists())->toBeFalse();
    $commission->refresh();
    expect($commission->status)->toBe(CommissionStatus::Pending);
    expect($commission->payout_batch_id)->toBeNull();
});

test('middleware sets cookie when ref query matches existing referral code', function () {
    $referrer = User::factory()->create();
    $referrer->refresh();

    $this->get(route('home', ['ref' => $referrer->referral_code]))
        ->assertCookie(config('referral.cookie_name'), $referrer->referral_code);
});
