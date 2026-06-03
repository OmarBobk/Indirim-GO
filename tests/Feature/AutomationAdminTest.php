<?php

declare(strict_types=1);

use App\Actions\Fulfillments\CancelFulfillmentAutomationRun;
use App\Actions\Fulfillments\CreateFulfillmentsForOrder;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\AutomationRunChanged;
use App\Jobs\DispatchFulfillmentAutomationJob;
use App\Livewire\Admin\AutomationMonitor;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Services\FulfillmentAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function makeAutomationAdminFulfillment(): Fulfillment
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

beforeEach(function (): void {
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
    ]);

    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    WebsiteSetting::instance()->update(['automation_enabled' => true]);
    Queue::fake();
});

function adminUser(): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

    $permissions = collect(config('permission.backend_permissions', []))
        ->map(fn (string $name): Permission => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]));

    $role->syncPermissions($permissions);
    $user->assignRole($role);

    return $user;
}

test('automation admin page is restricted to admins', function () {
    $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage_fulfillments', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->givePermissionTo($permission);

    $this->actingAs($user)
        ->get(route('admin.automation.index'))
        ->assertRedirect();
});

test('admin can view automation monitor page', function () {
    $this->actingAs(adminUser())
        ->get(route('admin.automation.index'))
        ->assertSuccessful()
        ->assertSeeLivewire(AutomationMonitor::class);
});

test('stats counts are correct on automation monitor', function () {
    $fulfillment = makeAutomationAdminFulfillment();

    FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:stats-running',
        'dispatched_at' => now(),
    ]);

    FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::NeedsReview,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:stats-review',
    ]);

    FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Failed,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:stats-failed',
        'updated_at' => now(),
    ]);

    $component = Livewire::actingAs(adminUser())->test(AutomationMonitor::class);
    $stats = $component->instance()->stats;

    expect($stats['running_count'])->toBe(1)
        ->and($stats['needs_review_count'])->toBe(1)
        ->and($stats['failed_today_count'])->toBe(1);
});

test('kill switch toggle changes website setting', function () {
    Livewire::actingAs(adminUser())
        ->test(AutomationMonitor::class)
        ->assertSet('automationEnabled', true)
        ->call('toggleAutomation')
        ->assertSet('automationEnabled', false);

    expect(WebsiteSetting::instance()->refresh()->automation_enabled)->toBeFalse();
    expect(app(FulfillmentAutomationService::class)->isEnabled())->toBeFalse();
});

test('retry action dispatches automation job when eligible', function () {
    $fulfillment = makeAutomationAdminFulfillment();
    $fulfillment->update(['status' => \App\Enums\FulfillmentStatus::Failed]);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Failed,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:retry-test',
        'finished_at' => now(),
    ]);

    Livewire::actingAs(adminUser())
        ->test(AutomationMonitor::class)
        ->call('retryRun', $run->uuid);

    Queue::assertPushed(DispatchFulfillmentAutomationJob::class);
});

test('cancel action cancels active automation run', function () {
    $fulfillment = makeAutomationAdminFulfillment();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:cancel-test',
    ]);

    Livewire::actingAs(adminUser())
        ->test(AutomationMonitor::class)
        ->call('cancelRun', $run->uuid);

    expect($run->refresh()->status)->toBe(FulfillmentAutomationRunStatus::Cancelled);
});

test('run duration label formats finished runs without intdiv errors', function () {
    $fulfillment = makeAutomationAdminFulfillment();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Succeeded,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:duration-test',
        'started_at' => now()->subMinutes(2)->subSeconds(15),
        'finished_at' => now(),
    ]);

    $component = Livewire::actingAs(adminUser())->test(AutomationMonitor::class);

    expect($component->instance()->runDurationLabel($run->fresh()))->toBe('2m 15s');
});

test('selecting a run uuid sets selected run', function () {
    $fulfillment = makeAutomationAdminFulfillment();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::NeedsReview,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:select-test',
        'error_code' => 'margin_insufficient',
    ]);

    $component = Livewire::actingAs(adminUser())
        ->test(AutomationMonitor::class)
        ->call('selectRun', $run->uuid)
        ->assertSet('selectedRunUuid', $run->uuid);

    expect($component->instance()->selectedRun?->uuid)->toBe($run->uuid);
});

test('automation service stays disabled when env flag is off even if db toggle is on', function () {
    config(['fulfillment_automation.enabled' => false]);
    WebsiteSetting::instance()->update(['automation_enabled' => true]);

    expect(app(FulfillmentAutomationService::class)->isEnabled())->toBeFalse();
});

test('cancelling an automation run broadcasts AutomationRunChanged', function () {
    Event::fake([AutomationRunChanged::class]);

    $fulfillment = makeAutomationAdminFulfillment();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:cancel-broadcast',
        'started_at' => now(),
    ]);

    app(CancelFulfillmentAutomationRun::class)->handle($fulfillment, 'admin_cancel');

    Event::assertDispatched(AutomationRunChanged::class, function (AutomationRunChanged $event) use ($run): bool {
        return $event->runUuid === $run->uuid
            && $event->type === 'cancelled'
            && $event->status === FulfillmentAutomationRunStatus::Cancelled->value;
    });
});

test('automation monitor refreshes when automation-run-updated is dispatched', function () {
    $fulfillment = makeAutomationAdminFulfillment();

    FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::NeedsReview,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:refresh-test',
    ]);

    $component = Livewire::actingAs(adminUser())->test(AutomationMonitor::class);

    expect($component->instance()->stats['needs_review_count'])->toBe(1);

    FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::NeedsReview,
        'attempt' => 2,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:refresh-test-2',
    ]);

    $component->dispatch('automation-run-updated', ['type' => 'needs_review']);

    expect($component->instance()->stats['needs_review_count'])->toBe(2);
});
