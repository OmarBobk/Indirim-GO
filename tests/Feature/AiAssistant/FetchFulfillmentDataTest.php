<?php

declare(strict_types=1);

use App\Actions\AiAssistant\FetchFulfillmentData;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentLogLevel;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\FulfillmentLog;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Str;

function makeAssistantFulfillment(): Fulfillment
{
    $user = User::factory()->create(['username' => 'zain']);
    $package = Package::factory()->create(['fulfillment_provider' => 'browser:wasim']);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 25]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => 'ORD-ASSIST-'.Str::upper(Str::random(4)),
        'currency' => 'USD',
        'subtotal' => 25,
        'fee' => 0,
        'total' => 25,
        'status' => OrderStatus::Paid,
    ]);

    $orderItem = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 25,
        'quantity' => 1,
        'line_total' => 25,
        'status' => OrderItemStatus::Pending,
    ]);

    return Fulfillment::query()->create([
        'order_id' => $order->id,
        'order_item_id' => $orderItem->id,
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Queued,
        'attempts' => 2,
    ]);
}

it('returns fulfillment data including latest automation run when present', function (): void {
    $fulfillment = makeAssistantFulfillment();

    FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::NeedsReview,
        'attempt' => 2,
        'error_code' => 'margin_insufficient',
        'error_message' => 'Insufficient margin for supplier purchase',
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:2',
        'started_at' => now()->subMinute(),
        'finished_at' => now(),
    ]);

    FulfillmentLog::query()->create([
        'fulfillment_id' => $fulfillment->id,
        'level' => FulfillmentLogLevel::Info,
        'message' => 'Fulfillment queued',
    ]);

    $data = app(FetchFulfillmentData::class)->handle($fulfillment->id);

    expect($data)->not->toBeNull();
    expect($data['fulfillment_id'])->toBe($fulfillment->id);
    expect($data['latest_automation_run'])->not->toBeNull();
    expect($data['latest_automation_run']['supplier_key'])->toBe('wasim');
    expect($data['recent_logs'])->toHaveCount(1);
});

it('returns fulfillment data with null automation run when none exists', function (): void {
    $fulfillment = makeAssistantFulfillment();

    $data = app(FetchFulfillmentData::class)->handle($fulfillment->id);

    expect($data)->not->toBeNull();
    expect($data['latest_automation_run'])->toBeNull();
});

it('returns null when fulfillment id not found', function (): void {
    expect(app(FetchFulfillmentData::class)->handle(999999))->toBeNull();
});
