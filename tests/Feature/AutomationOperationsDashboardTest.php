<?php

declare(strict_types=1);

use App\Actions\Dashboard\GetAdminExceptionCounts;
use App\Actions\Fulfillments\GetAutomationOperationsDashboard;
use App\Enums\AutomationRunLiveness;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Livewire\Admin\AutomationMonitor;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\Automation\AutomationRunLivenessClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
        'fulfillment_automation.liveness.purchase_slow_seconds' => 180,
        'fulfillment_automation.liveness.purchase_stale_seconds' => 480,
        'fulfillment_automation.liveness.legacy_fallback_stale_minutes' => 30,
    ]);

    Role::firstOrCreate(['name' => 'admin']);
    WebsiteSetting::query()->delete();
    WebsiteSetting::create(['automation_enabled' => true]);
    Http::fake([
        'http://automation-worker.test/health' => Http::response([
            'status' => 'ok',
            'ready' => true,
            'build' => '2026-08-01-c1.1-progress',
            'instance_id' => 'inst-1',
            'uptime_seconds' => 12,
            'active_count' => 1,
            'configured_max_concurrency' => 1,
            'browser_available' => true,
            'driver_versions' => ['wasim' => 'wasim-1.0.0'],
            'wasim_submit_purchase' => true,
            'wasim_reconcile' => true,
        ], 200),
    ]);
});

function seedOpsFulfillment(array $fulfillmentMeta = [], array $runAttrs = []): array
{
    $user = User::factory()->create();
    $package = Package::factory()->create(['fulfillment_provider' => 'browser:wasim', 'name' => 'Ops Pack']);
    $product = Product::factory()->create(['package_id' => $package->id, 'name' => 'Ops Product', 'entry_price' => 5]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => 'ORD-OPS-'.uniqid(),
        'currency' => 'USD',
        'subtotal' => 5,
        'fee' => 0,
        'total' => 5,
        'status' => OrderStatus::Paid,
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 5,
        'quantity' => 1,
        'line_total' => 5,
        'status' => OrderItemStatus::Pending,
        'requirements_payload' => ['player_id' => 'SECRET-PLAYER'],
    ]);

    $fulfillment = Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Processing,
        'attempts' => 0,
        'meta' => ['automation' => $fulfillmentMeta],
    ]);

    $run = FulfillmentAutomationRun::create(array_merge([
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:'.uniqid(),
        'dispatched_at' => now(),
        'started_at' => now(),
        'progress_sequence' => 3,
        'last_heartbeat_at' => now(),
        'current_step_started_at' => now()->subMinute(),
        'progress_snapshot' => [
            'step' => 'submitting_purchase',
            'phase' => 'purchase',
            'worker_build' => '2026-08-01-c1.1-progress',
            'driver_version' => 'wasim-1.0.0',
        ],
    ], $runAttrs));

    return [$fulfillment->fresh(), $run->fresh()];
}

it('builds working-now waiting scheduled and needs-attention sections', function (): void {
    [$activeFulfillment, $activeRun] = seedOpsFulfillment();

    [$waitingFulfillment] = seedOpsFulfillment(
        fulfillmentMeta: [
            'awaiting_wasim_reconcile' => true,
            'supplier_order_id' => 'W-100',
        ],
        runAttrs: [
            'status' => FulfillmentAutomationRunStatus::Succeeded,
            'finished_at' => now(),
            'external_order_id' => 'W-100',
            'idempotency_key' => 'automation:fulfillment:wait:attempt:1',
        ],
    );

    [$scheduledFulfillment] = seedOpsFulfillment(
        fulfillmentMeta: [
            'awaiting_wasim_reconcile' => true,
            'supplier_order_id' => 'W-200',
            'next_reconcile_at' => now()->addMinutes(10)->toIso8601String(),
        ],
        runAttrs: [
            'status' => FulfillmentAutomationRunStatus::Succeeded,
            'finished_at' => now(),
            'external_order_id' => 'W-200',
            'idempotency_key' => 'automation:fulfillment:sched:attempt:1',
        ],
    );

    [$exhaustedFulfillment] = seedOpsFulfillment(
        fulfillmentMeta: [
            'awaiting_wasim_reconcile' => true,
            'requires_review' => true,
            'supplier_order_id' => 'W-300',
        ],
        runAttrs: [
            'status' => FulfillmentAutomationRunStatus::Succeeded,
            'finished_at' => now(),
            'external_order_id' => 'W-300',
            'idempotency_key' => 'automation:fulfillment:exh:attempt:1',
        ],
    );

    FulfillmentAutomationRun::create([
        'fulfillment_id' => $activeFulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::NeedsReview,
        'attempt' => 2,
        'idempotency_key' => 'automation:fulfillment:'.$activeFulfillment->id.':attempt:review',
        'finished_at' => now(),
        'error_code' => 'automation_timeout',
    ]);

    $dashboard = app(GetAutomationOperationsDashboard::class)->handle();

    expect(collect($dashboard->workingNow)->pluck('runUuid'))->toContain($activeRun->uuid)
        ->and(collect($dashboard->waitingSupplier)->pluck('fulfillmentId'))->toContain($waitingFulfillment->id)
        ->and(collect($dashboard->scheduledReconcile)->pluck('fulfillmentId'))->toContain($scheduledFulfillment->id)
        ->and(collect($dashboard->needsAttention)->pluck('fulfillmentId'))->toContain($exhaustedFulfillment->id);

    $encoded = json_encode($dashboard);
    expect($encoded)->not->toContain('SECRET-PLAYER')
        ->and($encoded)->not->toContain('player_id');
});

it('classifies heartbeat liveness and does not mark waiting supplier as stale', function (): void {
    $classifier = app(AutomationRunLivenessClassifier::class);

    [, $healthy] = seedOpsFulfillment(runAttrs: ['last_heartbeat_at' => now()]);
    expect($classifier->classifyActiveWorkerRun($healthy))->toBe(AutomationRunLiveness::Healthy);

    [, $slow] = seedOpsFulfillment(runAttrs: [
        'last_heartbeat_at' => now()->subSeconds(200),
        'idempotency_key' => 'automation:fulfillment:slow:1',
    ]);
    expect($classifier->classifyActiveWorkerRun($slow))->toBe(AutomationRunLiveness::Slow);

    [, $stale] = seedOpsFulfillment(runAttrs: [
        'last_heartbeat_at' => now()->subSeconds(500),
        'idempotency_key' => 'automation:fulfillment:stale:1',
    ]);
    expect($classifier->classifyActiveWorkerRun($stale))->toBe(AutomationRunLiveness::Stale);

    [$waiting] = seedOpsFulfillment(
        fulfillmentMeta: ['awaiting_wasim_reconcile' => true, 'supplier_order_id' => 'W-9'],
        runAttrs: [
            'status' => FulfillmentAutomationRunStatus::Succeeded,
            'finished_at' => now()->subHours(2),
            'idempotency_key' => 'automation:fulfillment:wait2:1',
        ],
    );

    expect($classifier->isWaitingSupplier($waiting))->toBeTrue()
        ->and($classifier->isReconcileExhausted($waiting))->toBeFalse();
});

it('includes reconcile exhaustion in admin exception counts', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    seedOpsFulfillment(
        fulfillmentMeta: [
            'awaiting_wasim_reconcile' => true,
            'requires_review' => true,
            'supplier_order_id' => 'W-EX',
        ],
        runAttrs: [
            'status' => FulfillmentAutomationRunStatus::Succeeded,
            'finished_at' => now(),
            'idempotency_key' => 'automation:fulfillment:excount:1',
        ],
    );

    $counts = app(GetAdminExceptionCounts::class)->handle($admin);
    expect($counts['automation_needs_review'])->toBeGreaterThan(0);
});

it('renders operations board without customer requirement secrets', function (): void {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    seedOpsFulfillment();

    Livewire::actingAs($admin)
        ->test(AutomationMonitor::class)
        ->assertSuccessful()
        ->assertSee(__('messages.automation_working_now'))
        ->assertDontSee('SECRET-PLAYER');
});

it('presents succeeded purchase awaiting reconcile honestly', function (): void {
    [, $run] = seedOpsFulfillment(
        fulfillmentMeta: [
            'awaiting_wasim_reconcile' => true,
            'supplier_order_id' => 'W-OK',
        ],
        runAttrs: [
            'status' => FulfillmentAutomationRunStatus::Succeeded,
            'finished_at' => now(),
            'external_order_id' => 'W-OK',
            'idempotency_key' => 'automation:fulfillment:honest:1',
        ],
    );

    $dashboard = app(GetAutomationOperationsDashboard::class)->handle();
    $recent = collect($dashboard->recentOutcomes)->firstWhere('runUuid', $run->uuid);

    expect($recent)->not->toBeNull()
        ->and($recent->presentation)->toBe('supplier_accepted_awaiting_reconcile');
});
