<?php

declare(strict_types=1);

use App\Actions\Commissions\CreatePayoutBatch;
use App\Actions\Orders\RefundOrderItem;
use App\Actions\Refunds\ApproveRefundRequest;
use App\Actions\Topups\ApproveTopupRequest;
use App\Actions\Topups\CreateTopupRequestAction;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\TopupRequestStatus;
use App\Events\TopupRequestsChanged;
use App\Models\Commission;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\PayoutBatch;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\CommissionCreditedNotification;
use App\Notifications\RefundApprovedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin']);

    foreach (['manage_topups', 'process_refunds', 'manage_settlements'] as $permission) {
        Permission::firstOrCreate(['name' => $permission]);
    }
});

function grantPermission(User $user, string $permission): User
{
    $user->givePermissionTo($permission);

    return $user;
}

/**
 * @return array{order: Order, fulfillment: Fulfillment, item: OrderItem}
 */
function makeFailedFulfillmentForAuthTests(User $customer): array
{
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 30,
    ]);

    $order = Order::create([
        'user_id' => $customer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 30,
        'fee' => 0,
        'total' => 30,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 30,
        'quantity' => 1,
        'line_total' => 30,
        'status' => OrderItemStatus::Failed,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 1,
        'last_error' => 'Provider error',
    ]);

    return [
        'order' => $order,
        'fulfillment' => $fulfillment,
        'item' => $item,
    ];
}

it('denies top-up approval without manage_topups and mutates nothing', function () {
    Notification::fake();
    Event::fake([TopupRequestsChanged::class]);

    $customer = User::factory()->create();
    $intruder = User::factory()->create();
    $wallet = Wallet::forUser($customer);

    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $customer->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 80,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    $txCountBefore = WalletTransaction::query()->count();

    expect(fn () => app(ApproveTopupRequest::class)->handle($intruder, $request))
        ->toThrow(AuthorizationException::class);

    $wallet->refresh();
    $request->refresh();

    expect((float) $wallet->balance)->toBe(0.0);
    expect($request->status)->toBe(TopupRequestStatus::Pending);
    expect(WalletTransaction::query()->count())->toBe($txCountBefore);
    expect($request->walletTransaction?->status)->toBe(WalletTransaction::STATUS_PENDING);
    Notification::assertNothingSent();
    Event::assertNotDispatched(TopupRequestsChanged::class);
});

it('denies a customer from approving their own top-up', function () {
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);

    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $customer->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 50,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    expect(fn () => app(ApproveTopupRequest::class)->handle($customer, $request))
        ->toThrow(AuthorizationException::class);

    expect($request->fresh()->status)->toBe(TopupRequestStatus::Pending);
    expect((float) $wallet->fresh()->balance)->toBe(0.0);
});

it('authorizes top-up approval from the explicit actor not the global auth user', function () {
    $customer = User::factory()->create();
    $privileged = grantPermission(User::factory()->create(), 'manage_topups');
    $loggedInWithoutPermission = User::factory()->create();
    $wallet = Wallet::forUser($customer);

    $request = app(CreateTopupRequestAction::class)->handle([
        'user_id' => $customer->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 40,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    $this->actingAs($loggedInWithoutPermission);

    app(ApproveTopupRequest::class)->handle($privileged, $request);

    expect($request->fresh()->status)->toBe(TopupRequestStatus::Approved);
    expect((float) $wallet->fresh()->balance)->toBe(40.0);
    expect($request->fresh()->approved_by)->toBe($privileged->id);
});

it('denies refund approval without process_refunds and mutates nothing', function () {
    Notification::fake();

    $customer = User::factory()->create();
    $intruder = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $payload = makeFailedFulfillmentForAuthTests($customer);

    $refundTx = (new RefundOrderItem)->handle($payload['fulfillment'], $customer->id);
    $txCountBefore = WalletTransaction::query()->count();

    expect(fn () => app(ApproveRefundRequest::class)->handle($intruder, $refundTx->id))
        ->toThrow(AuthorizationException::class);

    expect($refundTx->fresh()->status)->toBe(WalletTransaction::STATUS_PENDING);
    expect((float) $wallet->fresh()->balance)->toBe(0.0);
    expect($payload['order']->fresh()->status)->toBe(OrderStatus::Paid);
    expect(WalletTransaction::query()->count())->toBe($txCountBefore);
    Notification::assertNotSentTo($customer, RefundApprovedNotification::class);
});

it('denies an order owner from approving their own refund without process_refunds', function () {
    $customer = User::factory()->create();
    Wallet::forUser($customer);
    $payload = makeFailedFulfillmentForAuthTests($customer);
    $refundTx = (new RefundOrderItem)->handle($payload['fulfillment'], $customer->id);

    expect(fn () => app(ApproveRefundRequest::class)->handle($customer, $refundTx->id))
        ->toThrow(AuthorizationException::class);

    expect($refundTx->fresh()->status)->toBe(WalletTransaction::STATUS_PENDING);
});

it('authorizes refund approval from the explicit actor not the global auth user', function () {
    $customer = User::factory()->create();
    $privileged = grantPermission(User::factory()->create(), 'process_refunds');
    $loggedInWithoutPermission = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $payload = makeFailedFulfillmentForAuthTests($customer);
    $refundTx = (new RefundOrderItem)->handle($payload['fulfillment'], $customer->id);

    $this->actingAs($loggedInWithoutPermission);

    app(ApproveRefundRequest::class)->handle($privileged, $refundTx->id);

    expect($refundTx->fresh()->status)->toBe(WalletTransaction::STATUS_POSTED);
    expect((float) $wallet->fresh()->balance)->toBe(30.0);
});

it('denies payout batch creation without manage_settlements and creates no batch or credits', function () {
    Notification::fake();

    $salesperson = User::factory()->create();
    $intruder = User::factory()->create();
    $buyer = User::factory()->create();
    Wallet::forUser($salesperson);

    $order = Order::create([
        'user_id' => $buyer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Sample',
        'unit_price' => 100,
        'entry_price' => 80,
        'quantity' => 1,
        'line_total' => 100,
    ]);

    $fulfillment = Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $buyer->id,
        'referral_code' => 'AUTHTEST1',
        'order_total' => 100,
        'commission_amount' => 20,
        'status' => CommissionStatus::Pending,
    ]);

    $txCountBefore = WalletTransaction::query()->count();

    expect(fn () => app(CreatePayoutBatch::class)->handle($intruder, [$commission->id], null, false))
        ->toThrow(AuthorizationException::class);

    expect(PayoutBatch::query()->count())->toBe(0);
    expect($commission->fresh()->status)->toBe(CommissionStatus::Pending);
    expect(WalletTransaction::query()->count())->toBe($txCountBefore);
    expect((float) Wallet::forUser($salesperson)->fresh()->balance)->toBe(0.0);
    Notification::assertNotSentTo($salesperson, CommissionCreditedNotification::class);
});

it('denies a salesperson from creating a payout batch without manage_settlements', function () {
    Permission::firstOrCreate(['name' => 'view_referrals']);
    $salesperson = grantPermission(User::factory()->create(), 'view_referrals');

    expect(fn () => app(CreatePayoutBatch::class)->handle($salesperson, [1], null, false))
        ->toThrow(AuthorizationException::class);

    expect(PayoutBatch::query()->count())->toBe(0);
});

it('authorizes payout batch creation from the explicit actor not the global auth user', function () {
    Notification::fake();

    $privileged = grantPermission(User::factory()->create(), 'manage_settlements');
    $loggedInWithoutPermission = User::factory()->create();
    $salesperson = User::factory()->create();
    $buyer = User::factory()->create();
    Wallet::forUser($salesperson);

    $order = Order::create([
        'user_id' => $buyer->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 100,
        'fee' => 0,
        'total' => 100,
        'status' => OrderStatus::Paid,
        'paid_at' => now()->subDays(4),
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'name' => 'Sample',
        'unit_price' => 100,
        'entry_price' => 80,
        'quantity' => 1,
        'line_total' => 100,
    ]);

    $fulfillment = Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Completed,
    ]);

    $commission = Commission::query()->create([
        'order_id' => $order->id,
        'fulfillment_id' => $fulfillment->id,
        'salesperson_id' => $salesperson->id,
        'customer_id' => $buyer->id,
        'referral_code' => 'AUTHTEST2',
        'order_total' => 100,
        'commission_amount' => 15,
        'status' => CommissionStatus::Pending,
    ]);

    $this->actingAs($loggedInWithoutPermission);

    $batch = app(CreatePayoutBatch::class)->handle($privileged, [$commission->id], null, false);

    expect($batch->created_by)->toBe($privileged->id);
    expect($commission->fresh()->status)->toBe(CommissionStatus::Credited);
    expect((float) Wallet::forUser($salesperson)->fresh()->balance)->toBe(15.0);
});

it('keeps top-up approve Actions free of hidden auth helpers', function () {
    $source = file_get_contents(app_path('Actions/Topups/ApproveTopupRequest.php'));

    expect($source)->not->toContain('auth()')
        ->and($source)->not->toContain('request()')
        ->and($source)->toContain('User $actor');
});

it('keeps refund approve Actions free of hidden auth helpers', function () {
    $source = file_get_contents(app_path('Actions/Refunds/ApproveRefundRequest.php'));

    expect($source)->not->toContain('auth()')
        ->and($source)->not->toContain('request()')
        ->and($source)->toContain('User $actor');
});

it('keeps payout batch Actions free of hidden auth helpers', function () {
    $source = file_get_contents(app_path('Actions/Commissions/CreatePayoutBatch.php'));

    expect($source)->not->toContain('auth()')
        ->and($source)->not->toContain('request()')
        ->and($source)->toContain('User $actor');
});
