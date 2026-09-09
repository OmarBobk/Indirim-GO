<?php

declare(strict_types=1);

use App\Actions\Fulfillments\BroadcastAutomationRunChanged;
use App\Actions\Fulfillments\CompleteFulfillment;
use App\Actions\Fulfillments\EnsureAutomationSupplierCircuits;
use App\Actions\Fulfillments\FailFulfillment;
use App\Actions\Fulfillments\PauseAutomationSupplierCircuit;
use App\Actions\Fulfillments\ReserveFulfillmentAutomationRun;
use App\Actions\Fulfillments\StartFulfillment;
use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitPauseReason;
use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Events\FulfillmentListChanged;
use App\Models\Category;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\AdminOpsBroadcaster;
use App\Support\FulfillmentListBroadcaster;
use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function c14bInstallFailingBroadcaster(string $exceptionMessage): void
{
    app('Illuminate\Broadcasting\BroadcastManager')->extend('failing', function () use ($exceptionMessage) {
        return new class($exceptionMessage) extends NullBroadcaster
        {
            public function __construct(private readonly string $failureMessage) {}

            public function broadcast(array $channels, $event, array $payload = []): void
            {
                throw new BroadcastException($this->failureMessage);
            }
        };
    });

    config([
        'broadcasting.default' => 'failing',
        'broadcasting.connections.failing' => [
            'driver' => 'failing',
        ],
    ]);
}

function c14bSignedBroadcastFailure(): string
{
    return 'Pusher error contacting http://127.0.0.1:8080/apps/demo/events'
        .'?auth_key=demo-key&auth_signature=signed-demo-signature&auth_timestamp=1710000000'
        .': cURL error 7: Failed to connect to 127.0.0.1 port 8080';
}

/**
 * @return list<array{message: string, context: array<string, mixed>}>
 */
function c14bCaptureWarnings(): array
{
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = [
                'message' => $event->message,
                'context' => $event->context,
            ];
        }
    });

    return $warnings;
}

function c14bMakeQueuedBrowserFulfillment(): Fulfillment
{
    WebsiteSetting::query()->updateOrCreate(['id' => 1], [
        'automation_enabled' => true,
    ]);

    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
    ]);

    $user = User::factory()->create();
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
        'requirements_payload' => ['id' => 'controlled-test'],
    ]);

    return Fulfillment::create([
        'order_id' => $order->id,
        'order_item_id' => $item->id,
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Queued,
        'meta' => [
            'requirements_payload' => ['id' => 'controlled-test'],
        ],
    ]);
}

test('fulfillment list broadcast failures are isolated without leaking signed transport details', function () {
    c14bInstallFailingBroadcaster(c14bSignedBroadcastFailure());
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = [
                'message' => $event->message,
                'context' => $event->context,
            ];
        }
    });

    FulfillmentListBroadcaster::dispatch(169, 'processing');

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]['message'])->toBe('Fulfillment list broadcast failed')
        ->and($warnings[0]['context'])->toMatchArray([
            'error_id' => 'fulfillment_list_broadcast_failed',
            'fulfillment_id' => 169,
            'type' => 'processing',
            'exception_class' => BroadcastException::class,
        ])
        ->and($warnings[0]['context'])->not->toHaveKey('message')
        ->and(json_encode($warnings[0], JSON_THROW_ON_ERROR))
        ->not->toContain('auth_key=', 'auth_signature=', 'signed-demo-signature');
});

test('admin ops broadcast failures are isolated', function () {
    c14bInstallFailingBroadcaster(c14bSignedBroadcastFailure());
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = $event->message;
        }
    });

    AdminOpsBroadcaster::dispatch('refund-requested');

    expect($warnings)->toContain('Admin ops inbox broadcast failed');
});

test('StartFulfillment succeeds when Reverb transport fails', function () {
    c14bInstallFailingBroadcaster(c14bSignedBroadcastFailure());
    $fulfillment = c14bMakeQueuedBrowserFulfillment();

    $result = app(StartFulfillment::class)->handle($fulfillment, 'automation', null, [
        'source' => 'automation',
    ]);

    expect($result->fresh()->status)->toBe(FulfillmentStatus::Processing);
});

test('automation run reservation keeps durable reserved state when broadcast fails', function () {
    c14bInstallFailingBroadcaster(c14bSignedBroadcastFailure());
    $fulfillment = c14bMakeQueuedBrowserFulfillment();

    $run = app(ReserveFulfillmentAutomationRun::class)->handle($fulfillment);

    expect($run->fresh()->status)->toBe(FulfillmentAutomationRunStatus::Reserved)
        ->and(FulfillmentAutomationRun::query()->where('fulfillment_id', $fulfillment->id)->count())->toBe(1);
});

test('automation run changed broadcast failures are isolated', function () {
    c14bInstallFailingBroadcaster(c14bSignedBroadcastFailure());
    $warnings = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = $event->message;
        }
    });

    app(BroadcastAutomationRunChanged::class)->handle('run-uuid', 'progress', 'running');

    expect($warnings)->toContain('Automation broadcast failed');
});

test('FailFulfillment and CompleteFulfillment succeed when broadcast transport fails', function () {
    c14bInstallFailingBroadcaster(c14bSignedBroadcastFailure());
    $fulfillment = c14bMakeQueuedBrowserFulfillment();
    app(StartFulfillment::class)->handle($fulfillment, 'automation');

    $failed = app(FailFulfillment::class)->handle($fulfillment->fresh(), 'supplier cancelled', 'automation');
    expect($failed->fresh()->status)->toBe(FulfillmentStatus::Failed);

    $other = c14bMakeQueuedBrowserFulfillment();
    app(StartFulfillment::class)->handle($other, 'automation');
    $completed = app(CompleteFulfillment::class)->handle($other->fresh(), null, 'automation');
    expect($completed->fresh()->status)->toBe(FulfillmentStatus::Completed);
});

test('circuit pause remains durable when automation broadcast transport fails', function () {
    c14bInstallFailingBroadcaster(c14bSignedBroadcastFailure());

    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'manage_fulfillments', 'guard_name' => 'web']);
    $adminRole->givePermissionTo('manage_fulfillments');
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    app(EnsureAutomationSupplierCircuits::class)->handle('wasim');

    $circuit = app(PauseAutomationSupplierCircuit::class)->handle(
        $admin,
        'wasim',
        AutomationCircuitCapability::Purchase,
        AutomationCircuitPauseReason::Investigation,
        'C1.4B broadcast isolation',
    );

    expect($circuit->fresh()->state->value)->toBe('paused_manual');
});

test('successful fulfillment list broadcasts still publish FulfillmentListChanged', function () {
    Event::fake([FulfillmentListChanged::class]);

    FulfillmentListBroadcaster::dispatch(42, 'processing');

    Event::assertDispatched(FulfillmentListChanged::class, function (FulfillmentListChanged $event): bool {
        return $event->fulfillmentId === 42 && $event->type === 'processing';
    });
});
