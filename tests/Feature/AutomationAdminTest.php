<?php

declare(strict_types=1);

use App\Actions\Fulfillments\CancelFulfillmentAutomationRun;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Events\AutomationRunChanged;
use App\Jobs\DispatchFulfillmentAutomationJob;
use App\Livewire\Admin\AutomationMonitor;
use App\Models\FulfillmentAutomationRun;
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

test('admin can save wasim credentials and payload uses database values', function () {
    config([
        'fulfillment_automation.suppliers.wasim.credentials.username' => 'env-user',
        'fulfillment_automation.suppliers.wasim.credentials.password' => 'env-pass',
    ]);

    Livewire::actingAs(adminUser())
        ->test(AutomationMonitor::class)
        ->set('wasimUsername', 'admin-wasim@example.com')
        ->set('wasimPassword', 'secret-from-admin')
        ->call('saveWasimCredentials')
        ->assertHasNoErrors();

    $settings = WebsiteSetting::instance()->refresh();

    expect($settings->wasim_automation_username)->toBe('admin-wasim@example.com')
        ->and($settings->hasWasimAutomationPassword())->toBeTrue()
        ->and($settings->getWasimAutomationPassword())->toBe('secret-from-admin');

    $credentials = app(FulfillmentAutomationService::class)->supplierConfig('wasim')['credentials'] ?? [];

    expect($credentials)->toBe([
        'username' => 'admin-wasim@example.com',
        'password' => 'secret-from-admin',
    ]);
});

test('wasim credentials fall back to env when not set in database', function () {
    config([
        'fulfillment_automation.suppliers.wasim.credentials.username' => 'env-only-user',
        'fulfillment_automation.suppliers.wasim.credentials.password' => 'env-only-pass',
    ]);

    $credentials = app(FulfillmentAutomationService::class)->supplierConfig('wasim')['credentials'] ?? [];

    expect($credentials)->toBe([
        'username' => 'env-only-user',
        'password' => 'env-only-pass',
    ]);
});

test('automation search finds runs by wasim external order id', function () {
    $fulfillment = makeAutomationAdminFulfillment();

    $matching = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Succeeded,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:wasim-order-search',
        'external_order_id' => 'WASIM-ORDER-99123',
        'finished_at' => now(),
    ]);

    FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Failed,
        'attempt' => 2,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:wasim-order-search-other',
        'external_order_id' => 'WASIM-ORDER-00001',
        'finished_at' => now(),
    ]);

    $component = Livewire::actingAs(adminUser())
        ->test(AutomationMonitor::class)
        ->set('search', '99123');

    expect($component->instance()->runs->pluck('id')->all())->toBe([$matching->id]);
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

test('automation monitor shows purchase parsed summary on runs table', function () {
    $fulfillment = makeAutomationAdminFulfillment();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Succeeded,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:purchase-summary',
        'external_order_id' => '12399',
        'log_excerpt' => [
            ['step' => 'purchase_parsed', 'level' => 'info', 'message' => 'order=12399 status=Processing_OK_wait price=1.1198680372596153'],
        ],
        'result_payload' => [
            'supplier_order_id' => '12399',
            'supplier_status' => 'Processing_OK_wait',
            'supplier_entry_price' => 1.1198680372596153,
        ],
    ]);

    $instance = Livewire::actingAs(adminUser())
        ->test(AutomationMonitor::class)
        ->instance();

    expect($instance->runLogSummary($run))->toBe('order=12399 status=Processing_OK_wait price=1.1198680372596153')
        ->and($instance->runHasExpandableDetails($run))->toBeTrue()
        ->and($instance->runPurchaseDetails($run))->toMatchArray([
            'order' => '12399',
            'status' => 'Processing_OK_wait',
            'price' => '1.1198680372596153',
        ]);

    Livewire::actingAs(adminUser())
        ->test(AutomationMonitor::class)
        ->assertSee('order=12399 status=Processing_OK_wait price=1.1198680372596153')
        ->assertSee(__('messages.automation_toggle_details'));
});

test('formatted log excerpt adds sequential ids and sorts by step order', function () {
    $fulfillment = makeAutomationAdminFulfillment();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Failed,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:log-format',
        'log_excerpt' => [
            ['step' => 'submit', 'level' => 'info', 'message' => 'Submitted', 'id' => 3],
            ['step' => 'login', 'level' => 'info', 'message' => 'Logged in', 'id' => 1],
            ['step' => 'product', 'level' => 'info', 'message' => 'Opened product', 'id' => 2],
        ],
    ]);

    $formatted = Livewire::actingAs(adminUser())
        ->test(AutomationMonitor::class)
        ->instance()
        ->formattedLogExcerpt($run);

    expect($formatted)->toHaveCount(3)
        ->and($formatted[0]['id'])->toBe(1)
        ->and($formatted[0]['step'])->toBe('login')
        ->and($formatted[1]['id'])->toBe(2)
        ->and($formatted[1]['step'])->toBe('product')
        ->and($formatted[2]['id'])->toBe(3)
        ->and($formatted[2]['step'])->toBe('submit');
});

test('only the global latest failed run is marked as retriable', function () {
    $fulfillment = makeAutomationAdminFulfillment();

    FulfillmentAutomationRun::query()
        ->where('fulfillment_id', $fulfillment->id)
        ->delete();

    $older = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Failed,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:older',
        'finished_at' => now()->subHour(),
    ]);
    $older->forceFill(['created_at' => now()->subHours(2)])->save();

    $latest = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Failed,
        'attempt' => 2,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:latest',
        'finished_at' => now(),
    ]);

    $component = Livewire::actingAs(adminUser())->test(AutomationMonitor::class);
    $instance = $component->instance();

    $latestUuid = $instance->latestRunUuidForFulfillment($fulfillment->id);

    expect($latestUuid)->toBe($latest->uuid)
        ->and($instance->isGlobalLatestRun($older, $latestUuid))->toBeFalse()
        ->and($instance->isGlobalLatestRun($latest, $latestUuid))->toBeTrue();

    $group = $instance->runGroups->firstWhere('fulfillment_id', $fulfillment->id);

    expect($group)->not->toBeNull()
        ->and($group->count)->toBe(2)
        ->and($group->primary->uuid)->toBe($latest->uuid)
        ->and($group->others->first()->uuid)->toBe($older->uuid);
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
