<?php

use App\Actions\Fulfillments\CompleteFulfillment;
use App\Actions\Orders\RefundOrderItem;
use App\Actions\Refunds\DismissPendingRefundForFulfillment;
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

uses(RefreshDatabase::class);

beforeEach(function (): void {
    \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'admin']);
});

/**
 * @return array{user: User, fulfillment: Fulfillment, refund: WalletTransaction}
 */
function makePendingRefundFixture(): array
{
    $user = User::factory()->create();
    Wallet::forUser($user);

    $package = Package::factory()->create();
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 30]);

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
        'last_error' => 'Timeout',
    ]);

    $refund = (new RefundOrderItem)->handle($fulfillment, $user->id);

    return compact('user', 'fulfillment', 'refund');
}

test('completing fulfillment dismisses pending refund request', function () {
    $admin = User::factory()->create();
    $fixture = makePendingRefundFixture();

    app(CompleteFulfillment::class)->handle(
        $fixture['fulfillment'],
        ['code' => 'DELIVERED'],
        'admin',
        $admin->id,
    );

    $fixture['refund']->refresh();
    $fixture['fulfillment']->refresh();

    expect($fixture['refund']->status)->toBe(WalletTransaction::STATUS_REJECTED)
        ->and(data_get($fixture['refund']->meta, 'dismiss_reason'))->toBe('fulfillment_completed')
        ->and($fixture['fulfillment']->status)->toBe(FulfillmentStatus::Completed)
        ->and(data_get($fixture['fulfillment']->meta, 'refund.status'))->toBe(WalletTransaction::STATUS_REJECTED);
});

test('dismiss pending refund action is idempotent', function () {
    $fixture = makePendingRefundFixture();

    app(DismissPendingRefundForFulfillment::class)->handle($fixture['fulfillment'], null);
    app(DismissPendingRefundForFulfillment::class)->handle($fixture['fulfillment'], null);

    expect(
        WalletTransaction::query()
            ->whereKey($fixture['refund']->id)
            ->value('status')
    )->toBe(WalletTransaction::STATUS_REJECTED);
});

test('reject refund request cannot re-process dismissed refund', function () {
    $admin = User::factory()->create();
    $fixture = makePendingRefundFixture();

    app(CompleteFulfillment::class)->handle($fixture['fulfillment'], ['code' => 'OK'], 'admin', $admin->id);

    $result = app(\App\Actions\Refunds\RejectRefundRequest::class)->handle($fixture['refund']->id, $admin->id);

    expect($result->status)->toBe(WalletTransaction::STATUS_REJECTED);
});
