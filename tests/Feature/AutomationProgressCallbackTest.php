<?php

declare(strict_types=1);

use App\Enums\FulfillmentAutomationProgressStep;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\AutomationRunChanged;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\FulfillmentAutomationRunEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Services\FulfillmentAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
        'fulfillment_automation.progress.max_payload_bytes' => 8192,
        'fulfillment_automation.progress.emitted_at_skew_seconds' => 300,
    ]);

    Role::firstOrCreate(['name' => 'admin']);
});

function makeRunningAutomationRun(): FulfillmentAutomationRun
{
    $user = User::factory()->create();
    $package = Package::factory()->create(['fulfillment_provider' => 'browser:wasim']);
    $product = Product::factory()->create(['package_id' => $package->id, 'entry_price' => 10]);

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
        'status' => OrderItemStatus::Pending,
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Processing,
        'attempts' => 0,
        'meta' => [],
    ]);

    return FulfillmentAutomationRun::create([
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:1',
        'dispatched_at' => now(),
        'started_at' => now(),
        'progress_sequence' => 0,
    ]);
}

function signAutomationBody(array $payload): array
{
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $signature = app(FulfillmentAutomationService::class)->signPayload($raw, $timestamp);

    return [$raw, $timestamp, $signature];
}

function progressPayload(array $overrides = []): array
{
    return array_merge([
        'progress_sequence' => 1,
        'phase' => 'purchase',
        'step' => FulfillmentAutomationProgressStep::WorkerReceived->value,
        'emitted_at' => now()->toIso8601String(),
        'heartbeat' => false,
        'safe_message_code' => null,
        'safe_params' => null,
        'worker_instance_id' => 'worker-test-1',
        'worker_build' => '2026-08-01-c1.1-progress',
        'driver_name' => 'wasim',
        'driver_version' => 'wasim-1.0.0',
        'detected_ui_version' => null,
        'page_contract_version' => null,
        'session_alias' => 'wasim-main',
    ], $overrides);
}

it('accepts a valid HMAC progress callback and creates an event on step change', function (): void {
    Event::fake([AutomationRunChanged::class]);

    $run = makeRunningAutomationRun();
    $payload = progressPayload(['progress_sequence' => 1, 'step' => 'opening_product']);
    [$raw, $timestamp, $signature] = signAutomationBody($payload);

    $this->call(
        'POST',
        '/internal/automation/runs/'.$run->uuid.'/progress',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTOMATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_AUTOMATION_SIGNATURE' => $signature,
        ],
        $raw,
    )->assertOk()
        ->assertJson(['status' => 'accepted', 'applied' => true]);

    $run->refresh();
    expect($run->progress_sequence)->toBe(1)
        ->and($run->currentProgressStep())->toBe('opening_product')
        ->and($run->last_heartbeat_at)->not->toBeNull()
        ->and($run->fulfillment->status)->toBe(FulfillmentStatus::Processing);

    expect(FulfillmentAutomationRunEvent::query()->where('run_id', $run->id)->count())->toBe(1);
    Event::assertDispatched(AutomationRunChanged::class);
});

it('rejects invalid HMAC on progress callback', function (): void {
    $run = makeRunningAutomationRun();
    $payload = progressPayload();
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    $this->call(
        'POST',
        '/internal/automation/runs/'.$run->uuid.'/progress',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTOMATION_TIMESTAMP' => (string) time(),
            'HTTP_X_AUTOMATION_SIGNATURE' => 'sha256=deadbeef',
        ],
        $raw,
    )->assertUnauthorized();
});

it('rejects unknown progress steps', function (): void {
    $run = makeRunningAutomationRun();
    $payload = progressPayload(['step' => 'click_random_button']);
    [$raw, $timestamp, $signature] = signAutomationBody($payload);

    $this->withHeaders([
        'Content-Type' => 'application/json',
        'Accept' => 'application/json',
        'X-Automation-Timestamp' => (string) $timestamp,
        'X-Automation-Signature' => $signature,
    ])->call(
        'POST',
        '/internal/automation/runs/'.$run->uuid.'/progress',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json',
            'HTTP_X_AUTOMATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_AUTOMATION_SIGNATURE' => $signature,
        ],
        $raw,
    )->assertStatus(422);
});

it('ignores duplicate and out-of-order sequences without creating extra events', function (): void {
    Event::fake([AutomationRunChanged::class]);
    $run = makeRunningAutomationRun();

    foreach ([1, 2] as $sequence) {
        $payload = progressPayload([
            'progress_sequence' => $sequence,
            'step' => $sequence === 1 ? 'worker_received' : 'browser_starting',
        ]);
        [$raw, $timestamp, $signature] = signAutomationBody($payload);
        $this->call(
            'POST',
            '/internal/automation/runs/'.$run->uuid.'/progress',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_X_AUTOMATION_TIMESTAMP' => (string) $timestamp,
                'HTTP_X_AUTOMATION_SIGNATURE' => $signature,
            ],
            $raw,
        )->assertOk();
    }

    $duplicate = progressPayload(['progress_sequence' => 2, 'step' => 'browser_starting', 'heartbeat' => true]);
    [$raw, $timestamp, $signature] = signAutomationBody($duplicate);
    $this->call(
        'POST',
        '/internal/automation/runs/'.$run->uuid.'/progress',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTOMATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_AUTOMATION_SIGNATURE' => $signature,
        ],
        $raw,
    )->assertOk()->assertJson(['applied' => false, 'reason' => 'duplicate']);

    $outOfOrder = progressPayload(['progress_sequence' => 1, 'step' => 'worker_received']);
    [$raw, $timestamp, $signature] = signAutomationBody($outOfOrder);
    $this->call(
        'POST',
        '/internal/automation/runs/'.$run->uuid.'/progress',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTOMATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_AUTOMATION_SIGNATURE' => $signature,
        ],
        $raw,
    )->assertOk()->assertJson(['applied' => false, 'reason' => 'out_of_order']);

    expect(FulfillmentAutomationRunEvent::query()->where('run_id', $run->id)->count())->toBe(2);
});

it('updates heartbeat without creating an event when step is unchanged', function (): void {
    Event::fake([AutomationRunChanged::class]);
    $run = makeRunningAutomationRun();

    $first = progressPayload(['progress_sequence' => 1, 'step' => 'submitting_purchase']);
    [$raw, $timestamp, $signature] = signAutomationBody($first);
    $this->call(
        'POST',
        '/internal/automation/runs/'.$run->uuid.'/progress',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTOMATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_AUTOMATION_SIGNATURE' => $signature,
        ],
        $raw,
    )->assertOk();

    Event::fake([AutomationRunChanged::class]);

    $heartbeat = progressPayload([
        'progress_sequence' => 2,
        'step' => 'submitting_purchase',
        'heartbeat' => true,
    ]);
    [$raw, $timestamp, $signature] = signAutomationBody($heartbeat);
    $this->call(
        'POST',
        '/internal/automation/runs/'.$run->uuid.'/progress',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTOMATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_AUTOMATION_SIGNATURE' => $signature,
        ],
        $raw,
    )->assertOk()->assertJson(['reason' => 'heartbeat']);

    expect(FulfillmentAutomationRunEvent::query()->where('run_id', $run->id)->count())->toBe(1);
    Event::assertNotDispatched(AutomationRunChanged::class);

    $run->refresh();
    expect($run->progress_sequence)->toBe(2)
        ->and($run->fulfillment->fresh()->status)->toBe(FulfillmentStatus::Processing);
});

it('no-ops progress for terminal runs without mutating status', function (): void {
    $run = makeRunningAutomationRun();
    $run->update([
        'status' => FulfillmentAutomationRunStatus::Succeeded,
        'finished_at' => now(),
    ]);

    $payload = progressPayload(['progress_sequence' => 5]);
    [$raw, $timestamp, $signature] = signAutomationBody($payload);

    $this->call(
        'POST',
        '/internal/automation/runs/'.$run->uuid.'/progress',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTOMATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_AUTOMATION_SIGNATURE' => $signature,
        ],
        $raw,
    )->assertOk()->assertJson(['reason' => 'terminal']);

    $run->refresh();
    expect($run->status)->toBe(FulfillmentAutomationRunStatus::Succeeded)
        ->and($run->progress_sequence)->toBe(0);
});

it('rejects oversized progress payloads', function (): void {
    config(['fulfillment_automation.progress.max_payload_bytes' => 200]);

    $run = makeRunningAutomationRun();
    $payload = progressPayload([
        'safe_message_code' => str_repeat('x', 500),
    ]);
    [$raw, $timestamp, $signature] = signAutomationBody($payload);

    $this->call(
        'POST',
        '/internal/automation/runs/'.$run->uuid.'/progress',
        [],
        [],
        [],
        [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_AUTOMATION_TIMESTAMP' => (string) $timestamp,
            'HTTP_X_AUTOMATION_SIGNATURE' => $signature,
        ],
        $raw,
    )->assertStatus(413);
});
