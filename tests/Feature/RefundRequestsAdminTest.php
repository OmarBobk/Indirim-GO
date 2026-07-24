<?php

use App\Actions\Fulfillments\RetryFulfillment;
use App\Actions\Orders\RefundOrderItem;
use App\Actions\Refunds\ApproveRefundRequest;
use App\Actions\Refunds\RejectRefundRequest;
use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Commission;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $permissionNames = [
        'view_refunds',
        'process_refunds',
        'view_fulfillments',
        'manage_fulfillments',
    ];
    $permissions = collect($permissionNames)
        ->map(fn (string $name) => Permission::firstOrCreate(['name' => $name]))
        ->all();

    $adminRole = Role::firstOrCreate(['name' => 'admin']);
    $adminRole->syncPermissions($permissions);
});

/**
 * @return array{item: OrderItem, fulfillment: Fulfillment, order: Order}
 */
function makeRefundableItem(User $user): array
{
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 30,
    ]);

    $order = Order::create([
        'user_id' => $user->id,
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
        'item' => $item,
        'fulfillment' => $fulfillment,
        'order' => $order,
    ];
}

test('admin can approve refund request and credit wallet once', function () {
    $role = Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $payload = makeRefundableItem($user);

    $this->actingAs($admin)
        ->get('/refunds')
        ->assertOk();

    $refundTx = (new RefundOrderItem)->handle($payload['fulfillment'], $user->id);
    $commission = Commission::query()->create([
        'order_id' => $payload['order']->id,
        'fulfillment_id' => $payload['fulfillment']->id,
        'salesperson_id' => User::factory()->create()->id,
        'customer_id' => $user->id,
        'referral_code' => 'REFCODE1',
        'order_total' => 30,
        'commission_amount' => 6,
        'status' => CommissionStatus::Pending,
    ]);

    $action = new ApproveRefundRequest;
    $action->handle($refundTx->id, $admin->id);
    $action->handle($refundTx->id, $admin->id);

    $wallet->refresh();
    $refundTx->refresh();
    $payload['order']->refresh();
    $commission->refresh();

    expect((float) $wallet->balance)->toBe(30.0);
    expect($refundTx->status)->toBe(WalletTransaction::STATUS_POSTED);
    expect($refundTx->idempotency_key)->toBe('refund:fulfillment:'.$payload['fulfillment']->id);
    expect($payload['order']->status)->toBe(OrderStatus::Refunded);
    expect($commission->status)->toBe(CommissionStatus::Failed);
    expect(
        WalletTransaction::query()
            ->where('idempotency_key', 'refund:fulfillment:'.$payload['fulfillment']->id)
            ->count()
    )->toBe(1);
    expect(Activity::query()
        ->where('event', 'refund.approved')
        ->where('log_name', 'payments')
        ->where('subject_type', WalletTransaction::class)
        ->where('subject_id', $refundTx->id)
        ->exists()
    )->toBeTrue();
    expect(Activity::query()
        ->where('event', 'order.refunded')
        ->where('log_name', 'orders')
        ->where('subject_type', Order::class)
        ->where('subject_id', $payload['order']->id)
        ->exists()
    )->toBeTrue();

    (new RetryFulfillment)->handle($payload['fulfillment'], 'customer', $user->id);
    $payload['fulfillment']->refresh();
    expect($payload['fulfillment']->status)->toBe(FulfillmentStatus::Failed);
});

test('approved refunds stay listed on the refund requests page', function () {
    $role = Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $user = User::factory()->create();
    Wallet::forUser($user);
    $payload = makeRefundableItem($user);

    $refundTx = (new RefundOrderItem)->handle($payload['fulfillment'], $user->id);
    (new ApproveRefundRequest)->handle($refundTx->id, $admin->id);

    $this->actingAs($admin)
        ->get('/refunds')
        ->assertOk()
        ->assertSee($payload['order']->order_number)
        ->assertSee(__('messages.posted'), false);
});

test('approving duplicate refund requests for same order only credits once', function () {
    $role = Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $payload = makeRefundableItem($user);

    $firstRefund = (new RefundOrderItem)->handle($payload['fulfillment'], $user->id);

    $secondRefund = WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => $payload['item']->unit_price,
        'status' => WalletTransaction::STATUS_PENDING,
        'reference_type' => Fulfillment::class,
        'reference_id' => $payload['fulfillment']->id,
        'meta' => [
            'order_id' => $payload['order']->id,
            'order_number' => $payload['order']->order_number,
            'order_item_id' => $payload['item']->id,
            'fulfillment_id' => $payload['fulfillment']->id,
        ],
    ]);

    $action = new ApproveRefundRequest;
    $action->handle($firstRefund->id, $admin->id);
    $action->handle($secondRefund->id, $admin->id);

    $wallet->refresh();
    $firstRefund->refresh();
    $secondRefund->refresh();
    $payload['order']->refresh();

    expect((float) $wallet->balance)->toBe(30.0);
    expect($payload['order']->status)->toBe(OrderStatus::Refunded);
    expect($firstRefund->status)->toBe(WalletTransaction::STATUS_POSTED);
    expect($secondRefund->status)->toBe(WalletTransaction::STATUS_PENDING);
    expect(
        WalletTransaction::query()
            ->where('idempotency_key', 'refund:fulfillment:'.$payload['fulfillment']->id)
            ->count()
    )->toBe(1);
});

test('admin can reject refund request and user can retry', function () {
    $role = Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $user = User::factory()->create();
    Wallet::forUser($user);
    $payload = makeRefundableItem($user);

    $refundTx = (new RefundOrderItem)->handle($payload['fulfillment'], $user->id);

    (new RejectRefundRequest)->handle($refundTx->id, $admin->id);

    $refundTx->refresh();
    expect($refundTx->status)->toBe(WalletTransaction::STATUS_REJECTED);

    (new RetryFulfillment)->handle($payload['fulfillment'], 'customer', $user->id);
    $payload['fulfillment']->refresh();
    expect($payload['fulfillment']->status)->toBe(FulfillmentStatus::Queued);
});

test('refunds table links to fulfillment details', function () {
    $role = Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $user = User::factory()->create();
    Wallet::forUser($user);
    $payload = makeRefundableItem($user);

    (new RefundOrderItem)->handle($payload['fulfillment'], $user->id);

    $this->actingAs($admin)
        ->get('/refunds')
        ->assertOk()
        ->assertSee(__('messages.view_fulfillment'))
        ->assertSee('fulfillment='.$payload['fulfillment']->id, false);

    Livewire::actingAs($admin)
        ->withQueryParams(['fulfillment' => (string) $payload['fulfillment']->id])
        ->test('pages::backend.fulfillments.index')
        ->assertSet('showDetailsModal', true)
        ->assertSet('selectedFulfillmentId', $payload['fulfillment']->id);
});

test('non-admin cannot access refund dashboard', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get('/refunds')
        ->assertNotFound();
});

test('refund approval validates transaction type and direction', function () {
    $role = Role::firstOrCreate(['name' => 'admin']);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    $tx = WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => 10,
        'status' => WalletTransaction::STATUS_PENDING,
    ]);

    $action = new ApproveRefundRequest;

    expect(fn () => $action->handle($tx->id, $admin->id))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
