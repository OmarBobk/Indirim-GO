<?php

declare(strict_types=1);

/**
 * Opt-in MySQL/MariaDB concurrency harness for C1 automation races.
 *
 * Safety gates (all required):
 * - AUTOMATION_CONCURRENCY_TESTS=1
 * - APP_ENV=testing
 * - database driver mysql/mariadb
 * - database name ends with `_concurrency`
 *
 * Never uses SQLite fallback. Never calls migrate:fresh.
 * Fixtures are committed (no RefreshDatabase) so child PHP processes share rows.
 */

use App\Actions\Fulfillments\EnsureAutomationSupplierCircuits;
use App\Actions\Fulfillments\PauseAutomationSupplierCircuit;
use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitPauseReason;
use App\Enums\AutomationCircuitState;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Models\AutomationSupplierCircuit;
use App\Models\Category;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\FulfillmentAutomationRunEvent;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsiteSetting;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function c14bConcurrencyContext(): void
{
    static $ready = false;
    static $skipReason = null;

    if ($skipReason !== null) {
        test()->markTestSkipped($skipReason);
    }

    if ($ready) {
        return;
    }

    $flag = (string) env('AUTOMATION_CONCURRENCY_TESTS', '');
    if (! in_array($flag, ['1', 'true', 'TRUE'], true)) {
        $skipReason = 'Opt-in MySQL automation concurrency harness — set AUTOMATION_CONCURRENCY_TESTS=1 (skipped).';
        test()->markTestSkipped($skipReason);
    }

    if (! app()->environment('testing')) {
        $skipReason = 'Opt-in concurrency harness requires APP_ENV=testing.';
        test()->markTestSkipped($skipReason);
    }

    $driver = DB::connection()->getDriverName();
    if (! in_array($driver, ['mysql', 'mariadb'], true)) {
        $skipReason = 'Opt-in concurrency harness requires mysql/mariadb (refusing '.$driver.').';
        test()->markTestSkipped($skipReason);
    }

    $database = (string) DB::connection()->getDatabaseName();
    if ($database === '' || ! str_ends_with($database, '_concurrency')) {
        $skipReason = 'Opt-in concurrency harness refuses database "'.$database.'" — name must end with _concurrency.';
        test()->markTestSkipped($skipReason);
    }

    if (! Schema::hasTable('fulfillment_automation_runs') || ! Schema::hasTable('automation_supplier_circuits')) {
        $skipReason = 'Disposable concurrency schema incomplete — migrate manually on the _concurrency DB (migrate:fresh is never auto-run).';
        test()->markTestSkipped($skipReason);
    }

    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'concurrency-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
        'fulfillment_automation.wasim_probe.enabled' => true,
        'broadcasting.default' => 'null',
    ]);

    WebsiteSetting::query()->updateOrCreate(['id' => 1], [
        'automation_enabled' => true,
    ]);

    $ready = true;
}

/**
 * @param  list<array{0: string, 1: array<string, mixed>}>  $jobs
 * @return list<array<string, mixed>>
 */
function c14bRace(array $jobs): array
{
    $script = base_path('tests/Concurrency/bin/automation_race.php');
    $env = array_merge($_ENV, [
        'APP_ENV' => 'testing',
        'APP_KEY' => (string) (config('app.key') ?: env('APP_KEY')),
        'DB_CONNECTION' => 'mysql',
        'DB_HOST' => (string) config('database.connections.mysql.host', '127.0.0.1'),
        'DB_PORT' => (string) config('database.connections.mysql.port', '3306'),
        'DB_DATABASE' => (string) DB::connection()->getDatabaseName(),
        'DB_USERNAME' => (string) config('database.connections.mysql.username', 'root'),
        'DB_PASSWORD' => (string) config('database.connections.mysql.password', ''),
        'BROADCAST_CONNECTION' => 'null',
        'CACHE_STORE' => 'file',
        'QUEUE_CONNECTION' => 'sync',
    ]);

    $results = Process::concurrently(function (Pool $pool) use ($jobs, $script, $env): void {
        foreach ($jobs as $index => [$action, $payload]) {
            $pool->as((string) $index)
                ->path(base_path())
                ->env($env)
                ->timeout(60)
                ->command([
                    PHP_BINARY,
                    $script,
                    $action,
                    json_encode($payload, JSON_THROW_ON_ERROR),
                ]);
        }
    });

    $decoded = [];
    foreach ($jobs as $index => $unused) {
        $output = trim($results[(string) $index]->output());
        $decoded[$index] = json_decode($output !== '' ? $output : '{}', true, 512, JSON_THROW_ON_ERROR);
    }

    return $decoded;
}

function c14bResetWasimCircuits(): void
{
    app(EnsureAutomationSupplierCircuits::class)->handle('wasim');

    AutomationSupplierCircuit::query()
        ->where('supplier_key', 'wasim')
        ->get()
        ->each(function (AutomationSupplierCircuit $circuit): void {
            $circuit->forceFill([
                'state' => AutomationCircuitState::Enabled->value,
                'reason_code' => null,
                'safe_reason_context' => null,
                'opened_at' => null,
                'opened_by' => null,
                'opened_source' => null,
                'last_failure_at' => null,
                'consecutive_failure_count' => 0,
                'failure_window_started_at' => null,
                'recent_signal_keys' => [],
            ])->save();
        });
}

function c14bConcurrencyAdmin(): User
{
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'manage_fulfillments', 'guard_name' => 'web']);
    $role->givePermissionTo('manage_fulfillments');

    $admin = User::factory()->create([
        'email' => 'c14b.'.Str::lower(Str::random(8)).'@concurrency.test',
    ]);
    $admin->assignRole($role);

    return $admin;
}

function c14bConcurrencyQueuedFulfillment(): Fulfillment
{
    $user = User::factory()->create([
        'email' => 'buyer.'.Str::lower(Str::random(8)).'@concurrency.test',
    ]);
    $category = Category::factory()->create([
        'order' => fake()->unique()->numberBetween(1, 1_000_000),
    ]);
    $package = Package::factory()->create([
        'category_id' => $category->id,
        'order' => fake()->unique()->numberBetween(1, 1_000_000),
        'fulfillment_provider' => 'browser:wasim',
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 1.5,
        'product_api' => '/Customer/Home/ProductRequest?productId=274',
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 2.66,
        'fee' => 0,
        'total' => 2.66,
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
    ]);

    $item = OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 2.66,
        'quantity' => 1,
        'line_total' => 2.66,
        'status' => OrderItemStatus::Pending,
        'requirements_payload' => ['id' => 'concurrency-test'],
    ]);

    return Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Queued,
        'meta' => [
            'requirements_payload' => ['id' => 'concurrency-test'],
        ],
    ]);
}

test('two concurrent reservations create exactly one automation run', function () {
    c14bConcurrencyContext();
    c14bResetWasimCircuits();

    $fulfillment = c14bConcurrencyQueuedFulfillment();
    $payload = ['fulfillment_id' => $fulfillment->id];

    $results = c14bRace([
        ['reserve', $payload],
        ['reserve', $payload],
    ]);

    $successes = collect($results)->filter(fn (array $row): bool => ($row['successful'] ?? false) === true);
    $failures = collect($results)->filter(fn (array $row): bool => ($row['successful'] ?? false) !== true);

    expect($successes)->toHaveCount(1)
        ->and($failures)->toHaveCount(1)
        ->and(FulfillmentAutomationRun::query()->where('fulfillment_id', $fulfillment->id)->count())->toBe(1);
});

test('duplicate and out-of-order progress under concurrent writers stay monotonic', function () {
    c14bConcurrencyContext();

    $fulfillment = c14bConcurrencyQueuedFulfillment();
    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:1',
        'dispatched_at' => now(),
        'started_at' => now(),
        'progress_sequence' => 0,
    ]);

    $results = c14bRace([
        ['progress', ['run_id' => $run->id, 'sequence' => 1, 'step' => 'worker_received']],
        ['progress', ['run_id' => $run->id, 'sequence' => 1, 'step' => 'worker_received']],
        ['progress', ['run_id' => $run->id, 'sequence' => 0, 'step' => 'stale']],
    ]);

    $applied = collect($results)
        ->filter(fn (array $row): bool => ($row['successful'] ?? false) === true)
        ->filter(fn (array $row): bool => ($row['result']['applied'] ?? false) === true)
        ->count();

    expect($applied)->toBe(1)
        ->and(FulfillmentAutomationRunEvent::query()->where('run_id', $run->id)->count())->toBe(1)
        ->and($run->fresh()->progress_sequence)->toBe(1);
});

test('duplicate final callbacks under concurrency complete fulfillment once', function () {
    c14bConcurrencyContext();

    $fulfillment = c14bConcurrencyQueuedFulfillment();
    $fulfillment->update(['status' => FulfillmentStatus::Processing]);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:1',
        'dispatched_at' => now(),
        'started_at' => now(),
    ]);

    $body = [
        'outcome' => 'success',
        'external_order_id' => 'SUP-CONCURRENCY-1',
        'delivered_payload' => ['code' => 'SUP-CONCURRENCY-1'],
        'log_excerpt' => [['step' => 'done', 'message' => 'ok']],
    ];

    c14bRace([
        ['final', ['run_id' => $run->id, 'body' => $body]],
        ['final', ['run_id' => $run->id, 'body' => $body]],
    ]);

    expect($fulfillment->fresh()->status)->toBe(FulfillmentStatus::Completed)
        ->and($run->fresh()->status)->toBe(FulfillmentAutomationRunStatus::Succeeded)
        ->and($fulfillment->logs()->where('message', 'Fulfillment completed')->count())->toBe(1);
});

test('identical circuit open signals race to one paused_auto state', function () {
    c14bConcurrencyContext();
    c14bResetWasimCircuits();

    $sourceKey = 'open-race-'.Str::random(10);
    $input = [
        'supplier_key' => 'wasim',
        'failure_code' => 'unsupported_ui',
        'source_type' => 'probe',
        'source_key' => $sourceKey,
        'capability_hint' => 'purchase',
    ];

    c14bRace([
        ['signal', ['input' => $input]],
        ['signal', ['input' => $input]],
    ]);

    $circuit = AutomationSupplierCircuit::query()
        ->where('supplier_key', 'wasim')
        ->where('capability', AutomationCircuitCapability::Purchase->value)
        ->firstOrFail();

    expect($circuit->state)->toBe(AutomationCircuitState::PausedAuto)
        ->and(AutomationSupplierCircuit::query()
            ->where('supplier_key', 'wasim')
            ->where('capability', AutomationCircuitCapability::Purchase->value)
            ->count())->toBe(1);
});

test('two admin resumes against paused_manual remain single enabled transition', function () {
    c14bConcurrencyContext();
    c14bResetWasimCircuits();

    $admin = c14bConcurrencyAdmin();

    app(PauseAutomationSupplierCircuit::class)->handle(
        $admin,
        'wasim',
        AutomationCircuitCapability::Purchase,
        AutomationCircuitPauseReason::Investigation,
        'concurrency pause',
    );

    $results = c14bRace([
        ['resume', ['admin_id' => $admin->id, 'note' => 'resume-a']],
        ['resume', ['admin_id' => $admin->id, 'note' => 'resume-b']],
    ]);

    $enabled = AutomationSupplierCircuit::query()
        ->where('supplier_key', 'wasim')
        ->where('capability', AutomationCircuitCapability::Purchase->value)
        ->firstOrFail();

    expect($enabled->state)->toBe(AutomationCircuitState::Enabled)
        ->and(collect($results)->where('successful', true)->isNotEmpty())->toBeTrue();
});

test('failure signal versus manual pause leaves one circuit row', function () {
    c14bConcurrencyContext();
    c14bResetWasimCircuits();

    $admin = c14bConcurrencyAdmin();
    $signalKey = 'fail-vs-pause-'.Str::random(8);

    c14bRace([
        ['pause', ['admin_id' => $admin->id, 'note' => 'manual wins race']],
        ['signal', ['input' => [
            'supplier_key' => 'wasim',
            'failure_code' => 'unsupported_ui',
            'source_type' => 'run',
            'source_key' => $signalKey,
            'capability_hint' => 'purchase',
        ]]],
    ]);

    expect(AutomationSupplierCircuit::query()
        ->where('supplier_key', 'wasim')
        ->where('capability', AutomationCircuitCapability::Purchase->value)
        ->count())->toBe(1);

    $circuit = AutomationSupplierCircuit::query()
        ->where('supplier_key', 'wasim')
        ->where('capability', AutomationCircuitCapability::Purchase->value)
        ->firstOrFail();

    expect(in_array($circuit->state, [
        AutomationCircuitState::PausedManual,
        AutomationCircuitState::PausedAuto,
    ], true))->toBeTrue();
});
