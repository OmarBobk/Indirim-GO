<?php

declare(strict_types=1);

use App\Actions\Fulfillments\CancelFulfillmentAutomationRun;
use App\Actions\Fulfillments\ClaimFulfillment;
use App\Actions\Fulfillments\CreateFulfillmentsForOrder;
use App\Actions\Fulfillments\IngestFulfillmentAutomationResult;
use App\Actions\Fulfillments\ReserveFulfillmentAutomationRun;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Jobs\DispatchFulfillmentAutomationJob;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\FulfillmentAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
    ]);

    Role::firstOrCreate(['name' => 'admin']);
    Queue::fake();
    Http::fake();
});

function makeBrowserFulfillmentOrder(): Fulfillment
{
    $user = User::factory()->create();
    $package = Package::factory()->create([
        'fulfillment_provider' => 'browser:acme',
    ]);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 25]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 25,
        'fee' => 0,
        'total' => 25,
        'status' => OrderStatus::Paid,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 25,
        'quantity' => 1,
        'line_total' => 25,
        'status' => OrderItemStatus::Pending,
    ]);

    (new CreateFulfillmentsForOrder)->handle($order);

    return Fulfillment::query()->where('order_id', $order->id)->firstOrFail();
}

function signAutomationRequest(string $rawBody, ?int $timestamp = null): array
{
    $timestamp ??= time();
    $secret = (string) config('fulfillment_automation.callback_secret');
    $signature = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$rawBody, $secret);

    return [
        'X-Automation-Timestamp' => (string) $timestamp,
        'X-Automation-Signature' => $signature,
    ];
}

test('create fulfillments uses package browser provider', function () {
    $fulfillment = makeBrowserFulfillmentOrder();

    expect($fulfillment->provider)->toBe('browser:acme');
    expect($fulfillment->isBrowserAutomated())->toBeTrue();

    Queue::assertPushed(DispatchFulfillmentAutomationJob::class);
});

test('automation service eligibility requires queued unclaimed browser fulfillment', function () {
    $fulfillment = makeBrowserFulfillmentOrder();

    expect(app(FulfillmentAutomationService::class)->isEligible($fulfillment))->toBeTrue();

    $fulfillment->update(['claimed_by' => User::factory()->create()->id]);

    expect(app(FulfillmentAutomationService::class)->isEligible($fulfillment->refresh()))->toBeFalse();
});

test('reserve automation run is idempotent per attempt', function () {
    $fulfillment = makeBrowserFulfillmentOrder();

    $first = app(ReserveFulfillmentAutomationRun::class)->handle($fulfillment);
    expect($first->status)->toBe(FulfillmentAutomationRunStatus::Reserved);

    expect(fn () => app(ReserveFulfillmentAutomationRun::class)->handle($fulfillment->refresh()))
        ->toThrow(RuntimeException::class);
});

test('ingest success completes fulfillment and is idempotent', function () {
    $fulfillment = makeBrowserFulfillmentOrder();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:1',
        'dispatched_at' => now(),
        'started_at' => now(),
    ]);

    $fulfillment->update(['status' => FulfillmentStatus::Processing]);

    $payload = [
        'outcome' => 'success',
        'external_order_id' => 'SUP-12345',
        'delivered_payload' => ['code' => 'SUP-12345'],
        'log_excerpt' => [['step' => 'done', 'message' => 'ok']],
    ];

    app(IngestFulfillmentAutomationResult::class)->handle($run, $payload);

    $fulfillment->refresh();
    $run->refresh();

    expect($fulfillment->status)->toBe(FulfillmentStatus::Completed);
    expect($run->status)->toBe(FulfillmentAutomationRunStatus::Succeeded);
    expect(data_get($fulfillment->meta, 'delivered_payload.supplier_order_id'))->toBe('SUP-12345');

    app(IngestFulfillmentAutomationResult::class)->handle($run->refresh(), $payload);

    expect($fulfillment->logs()->where('message', 'Fulfillment completed')->count())->toBe(1);
});

test('callback endpoint accepts signed automation result', function () {
    $fulfillment = makeBrowserFulfillmentOrder();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:1',
        'started_at' => now(),
        'dispatched_at' => now(),
    ]);

    $fulfillment->update(['status' => FulfillmentStatus::Processing]);

    $body = json_encode([
        'outcome' => 'success',
        'external_order_id' => 'SUP-999',
        'delivered_payload' => ['code' => 'SUP-999'],
    ], JSON_THROW_ON_ERROR);

    $headers = signAutomationRequest($body);

    $this->postJson('/internal/automation/runs/'.$run->uuid.'/result', json_decode($body, true), $headers)
        ->assertSuccessful();

    expect($fulfillment->refresh()->status)->toBe(FulfillmentStatus::Completed);
});

test('claim cancels active automation run', function () {
    Permission::firstOrCreate(['name' => 'manage_fulfillments']);

    $supervisor = User::factory()->create();
    $supervisor->givePermissionTo('manage_fulfillments');

    $fulfillment = makeBrowserFulfillmentOrder();

    FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Reserved,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:1',
    ]);

    app(ClaimFulfillment::class)->handle($fulfillment, $supervisor->id);

    expect(FulfillmentAutomationRun::query()->where('fulfillment_id', $fulfillment->id)->first()?->status)
        ->toBe(FulfillmentAutomationRunStatus::Cancelled);
});

test('dispatch job posts to worker and marks run running', function () {
    Http::fake([
        'http://automation-worker.test/v1/runs' => Http::response(['accepted' => true], 202),
    ]);

    $fulfillment = makeBrowserFulfillmentOrder();

    (new DispatchFulfillmentAutomationJob($fulfillment->id))->handle(
        app(FulfillmentAutomationService::class),
        app(ReserveFulfillmentAutomationRun::class),
        app(\App\Actions\Fulfillments\StartFulfillment::class),
        app(\App\Actions\Fulfillments\DispatchFulfillmentAutomationRun::class),
    );

    $run = FulfillmentAutomationRun::query()->where('fulfillment_id', $fulfillment->id)->first();

    expect($run)->not->toBeNull();
    expect($run->status)->toBe(FulfillmentAutomationRunStatus::Running);
    expect($fulfillment->refresh()->status)->toBe(FulfillmentStatus::Processing);

    Http::assertSent(fn ($request) => $request->url() === 'http://automation-worker.test/v1/runs');
});

test('process fulfillments command skips browser providers', function () {
    $fulfillment = makeBrowserFulfillmentOrder();

    $this->artisan('fulfillment:process')->assertExitCode(0);

    expect($fulfillment->refresh()->status)->toBe(FulfillmentStatus::Queued);
});

test('cancel automation run marks active runs cancelled', function () {
    $fulfillment = makeBrowserFulfillmentOrder();

    FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:1',
    ]);

    $count = app(CancelFulfillmentAutomationRun::class)->handle($fulfillment, 'test');

    expect($count)->toBe(1);
    expect(FulfillmentAutomationRun::query()->first()?->status)->toBe(FulfillmentAutomationRunStatus::Cancelled);
});
