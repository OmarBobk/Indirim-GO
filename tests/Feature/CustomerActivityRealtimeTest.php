<?php

declare(strict_types=1);

use App\Actions\Orders\PayOrderWithWallet;
use App\Actions\Orders\RefundOrderItem;
use App\Actions\Topups\RejectTopupRequest;
use App\Enums\CustomerActivityInvalidationReason;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\TopupRequestStatus;
use App\Events\CustomerActivityInvalidated;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\TopupRejectedNotification;
use App\Support\CustomerActivityBroadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin']);
    Role::firstOrCreate(['name' => 'customer']);
    Permission::firstOrCreate(['name' => 'manage_topups']);
});

it('broadcasts customer activity invalidation on the authenticated user channel with allowlisted payload', function (): void {
    Event::fake([CustomerActivityInvalidated::class]);

    $user = User::factory()->create();

    CustomerActivityBroadcaster::dispatch($user, CustomerActivityInvalidationReason::OrderPaid);

    Event::assertDispatched(CustomerActivityInvalidated::class, function (CustomerActivityInvalidated $event) use ($user): bool {
        expect($event->userId)->toBe($user->id)
            ->and($event->reason)->toBe(CustomerActivityInvalidationReason::OrderPaid)
            ->and($event->broadcastWith())->toMatchArray([
                'reason' => 'order_paid',
            ])
            ->and(array_keys($event->broadcastWith()))->toBe(['reason', 'occurred_at', 'event_id']);

        return true;
    });
});

it('defers customer activity invalidation until after commit and skips rollback', function (): void {
    Event::fake([CustomerActivityInvalidated::class]);

    $user = User::factory()->create();

    try {
        DB::transaction(function () use ($user): void {
            CustomerActivityBroadcaster::dispatch($user, CustomerActivityInvalidationReason::OrderPaid);
            throw new RuntimeException('rollback');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(CustomerActivityInvalidated::class);

    DB::transaction(function () use ($user): void {
        CustomerActivityBroadcaster::dispatch($user, CustomerActivityInvalidationReason::OrderPaid);
    });

    Event::assertDispatched(CustomerActivityInvalidated::class);
});

it('dispatches customer activity invalidation immediately outside a transaction', function (): void {
    Event::fake([CustomerActivityInvalidated::class]);

    $user = User::factory()->create();

    CustomerActivityBroadcaster::dispatch($user, CustomerActivityInvalidationReason::RefundStateChanged);

    Event::assertDispatched(CustomerActivityInvalidated::class);
});

it('dispatches order paid invalidation when wallet payment completes', function (): void {
    Event::fake([CustomerActivityInvalidated::class]);

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

    app(PayOrderWithWallet::class)->handle($order, $wallet);

    Event::assertDispatched(CustomerActivityInvalidated::class, function (CustomerActivityInvalidated $event) use ($user): bool {
        return $event->userId === $user->id
            && $event->reason === CustomerActivityInvalidationReason::OrderPaid;
    });
});

it('dispatches refund state invalidation when a customer requests a refund on a failed fulfillment', function (): void {
    Event::fake([CustomerActivityInvalidated::class]);

    $user = User::factory()->create();
    ['order' => $order, 'fulfillment' => $fulfillment] = makeFailedOrderForRefundInvalidationTest($user);

    app(RefundOrderItem::class)->handle($fulfillment, $user->id, 'Please refund');

    Event::assertDispatched(CustomerActivityInvalidated::class, function (CustomerActivityInvalidated $event) use ($user): bool {
        return $event->userId === $user->id
            && $event->reason === CustomerActivityInvalidationReason::RefundStateChanged;
    });
});

it('does not dispatch domain invalidation when a customer notification already covers the mutation', function (): void {
    Event::fake([CustomerActivityInvalidated::class]);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $admin->givePermissionTo('manage_topups');

    $customer = User::factory()->create();
    $customer->assignRole('customer');
    $wallet = Wallet::forUser($customer);

    $topup = TopupRequest::query()->create([
        'user_id' => $customer->id,
        'wallet_id' => $wallet->id,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
        'amount' => 50,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
    ]);

    Wallet::query()->whereKey($wallet->id)->first();
    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => \App\Enums\WalletTransactionType::Topup,
        'direction' => \App\Enums\WalletTransactionDirection::Credit,
        'amount' => 50,
        'status' => WalletTransaction::STATUS_PENDING,
        'reference_type' => TopupRequest::class,
        'reference_id' => $topup->id,
        'idempotency_key' => 'topup-test-'.$topup->id,
    ]);

    app(RejectTopupRequest::class)->handle($topup, $admin->id, 'Rejected');

    Event::assertNotDispatched(CustomerActivityInvalidated::class);

    $customer->refresh();
    expect($customer->notifications()->where('type', TopupRejectedNotification::class)->count())->toBe(1);
});

it('scopes customer activity invalidation to the target user only', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();

    $event = new CustomerActivityInvalidated($owner->id, CustomerActivityInvalidationReason::OrderPaid);

    expect($event->broadcastOn()[0]->name)->toBe('private-App.Models.User.'.$owner->id)
        ->and($event->broadcastOn()[0]->name)->not->toBe('private-App.Models.User.'.$other->id);
});

/**
 * @return array{order: Order, fulfillment: Fulfillment, item: OrderItem}
 */
function makeFailedOrderForRefundInvalidationTest(User $user): array
{
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 10,
        'is_active' => true,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 10,
        'quantity' => 1,
        'line_total' => 10,
        'status' => OrderItemStatus::Failed,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'manual',
        'status' => FulfillmentStatus::Failed,
        'attempts' => 1,
        'meta' => ['last_error' => 'failed'],
    ]);

    return compact('order', 'fulfillment', 'item');
}
