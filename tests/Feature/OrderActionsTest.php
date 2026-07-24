<?php

use App\Actions\Orders\CreateOrderFromCartPayload;
use App\Actions\Orders\PayOrderWithWallet;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

test('creates order snapshot from cart payload using server prices', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'name' => 'Google Play 100$',
        'entry_price' => 100,
    ]);

    config()->set('billing.checkout_fee_fixed', 5);

    $payload = [
        [
            'id' => $product->id,
            'quantity' => 2,
            'price' => 1,
            'name' => 'Tampered',
        ],
    ];

    $action = new CreateOrderFromCartPayload;
    $order = $action->handle($user, $payload, ['ip' => '127.0.0.1']);

    $order->refresh();

    expect((float) $order->subtotal)->toBe(200.0);
    expect((float) $order->fee)->toBe(5.0);
    expect((float) $order->total)->toBe(205.0);
    expect($order->status)->toBe(OrderStatus::PendingPayment);
    expect($order->order_number)->toMatch('/^ORD-\d{4}-\d{6}$/');

    $item = $order->items->first();
    expect($item)->not->toBeNull();
    expect((float) $item->unit_price)->toBe(100.0);
    expect($item->name)->toBe('Google Play 100$');
    expect((float) $item->line_total)->toBe(200.0);
});

test('pays order with wallet once', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 100,
    ]);
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

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 100,
        'quantity' => 1,
        'line_total' => 100,
        'status' => OrderItemStatus::Pending,
    ]);

    $action = new PayOrderWithWallet;
    $action->handle($order, $wallet);
    $action->handle($order, $wallet);

    $wallet->refresh();
    $order->refresh();

    $transaction = WalletTransaction::query()
        ->where('reference_type', Order::class)
        ->where('reference_id', $order->id)
        ->firstOrFail();

    expect((float) $wallet->balance)->toBe(50.0);
    expect($transaction->type)->toBe(WalletTransactionType::Purchase);
    expect($transaction->direction)->toBe(WalletTransactionDirection::Debit);
    expect($transaction->status)->toBe(WalletTransaction::STATUS_POSTED);
    expect($order->status)->toBe(OrderStatus::Paid);
    expect($order->paid_at)->not->toBeNull();

    $fulfillments = Fulfillment::query()
        ->where('order_item_id', $orderItem->id)
        ->get();

    expect($fulfillments)->toHaveCount($orderItem->quantity);
    expect($fulfillments->pluck('status')->unique()->all())->toBe([FulfillmentStatus::Queued]);
});
