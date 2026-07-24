<?php

declare(strict_types=1);

use App\Actions\AiAssistant\FetchOrderData;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;

it('returns order data array for existing order number', function (): void {
    $user = User::factory()->create(['username' => 'zain']);
    $package = Package::factory()->create(['fulfillment_provider' => 'browser:wasim']);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 25]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => 'ORD-TEST-001',
        'currency' => 'USD',
        'subtotal' => 25,
        'fee' => 0,
        'total' => 25,
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
    ]);

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => 'Premium Package',
        'unit_price' => 25,
        'quantity' => 1,
        'line_total' => 25,
        'status' => OrderItemStatus::Pending,
    ]);

    $order->fulfillments()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Queued,
        'attempts' => 0,
    ]);

    $data = app(FetchOrderData::class)->handle('ORD-TEST-001');

    expect($data)->not->toBeNull();
    expect($data['order_number'])->toBe('ORD-TEST-001');
    expect($data['status'])->toBe('paid');
    expect($data['customer']['username'])->toBe('zain');
    expect($data['items'])->toHaveCount(1);
    expect($data['fulfillments'])->toHaveCount(1);
});

it('returns null when order number not found', function (): void {
    expect(app(FetchOrderData::class)->handle('ORD-MISSING'))->toBeNull();
});
