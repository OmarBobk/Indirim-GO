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
use App\Enums\ProductAmountMode;
use App\Enums\WalletTransactionType;
use App\Jobs\DispatchFulfillmentAutomationJob;
use App\Jobs\DispatchWasimReconcileJob;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\FulfillmentAutomationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
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

test('ingest supplier rejection fails fulfillment without refund when no order id', function () {
    $fulfillment = makeBrowserFulfillmentOrder();
    $order = Order::query()->findOrFail($fulfillment->order_id);
    Wallet::forUser(User::query()->findOrFail($order->user_id));

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

    $fulfillment->update(['status' => FulfillmentStatus::Processing]);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'failed',
        'error_code' => 'supplier_order_rejected',
        'message' => '{"replay":["invalid player id"]}',
        'delivered_payload' => [
            'supplier_entry_price' => 1.07,
            'supplier_status' => 'pending',
            'supplier_reply' => '{"replay":["invalid player id"]}',
        ],
    ]);

    $fulfillment->refresh();
    $run->refresh();

    expect($fulfillment->status)->toBe(FulfillmentStatus::Failed);
    expect($run->status)->toBe(FulfillmentAutomationRunStatus::Failed);
    expect($run->error_code)->toBe('supplier_order_rejected');

    expect(WalletTransaction::query()
        ->where('reference_type', Fulfillment::class)
        ->where('reference_id', $fulfillment->id)
        ->where('type', WalletTransactionType::Refund->value)
        ->exists())->toBeFalse();
});

test('ingest submitted with pending swal and supplier order id keeps processing and queues reconcile', function () {
    $fulfillment = makeBrowserFulfillmentOrder();
    $fulfillment->update(['provider' => 'browser:wasim']);

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

    $fulfillment->update(['status' => FulfillmentStatus::Processing]);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'submitted',
        'external_order_id' => '15586',
        'message' => 'Wasim order 15586 pending; reconcile on supplier orders page (رصيدك غير كافي).',
        'delivered_payload' => [
            'supplier_order_id' => '15586',
            'supplier_entry_price' => 26.03,
            'supplier_status' => 'pending',
            'supplier_reply' => 'رصيدك غير كافي',
            'checkpoint' => 'purchase_submitted_pending',
            'phase' => 'purchase',
        ],
    ]);

    $fulfillment->refresh();
    $run->refresh();

    expect($fulfillment->status)->toBe(FulfillmentStatus::Processing)
        ->and($run->status)->toBe(FulfillmentAutomationRunStatus::Succeeded)
        ->and(data_get($fulfillment->meta, 'automation.awaiting_wasim_reconcile'))->toBeTrue()
        ->and(data_get($fulfillment->meta, 'automation.supplier_order_id'))->toBe('15586');

    Queue::assertPushed(DispatchWasimReconcileJob::class);

    expect(WalletTransaction::query()
        ->where('reference_type', Fulfillment::class)
        ->where('reference_id', $fulfillment->id)
        ->where('type', WalletTransactionType::Refund->value)
        ->exists())->toBeFalse();
});

test('ingest success stores supplier entry price on order item', function () {
    $fulfillment = makeBrowserFulfillmentOrder();
    $item = OrderItem::query()->findOrFail($fulfillment->order_item_id);
    $item->update(['entry_price' => 99]);

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

    $fulfillment->update(['status' => FulfillmentStatus::Processing]);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'success',
        'external_order_id' => '12336',
        'delivered_payload' => [
            'supplier_entry_price' => 1.07692159,
            'supplier_status' => 'Completed',
        ],
    ]);

    expect((float) $item->refresh()->entry_price)->toBe(1.07692159);
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

test('dispatch job includes package and product api in worker payload', function () {
    Http::fake([
        'http://automation-worker.test/v1/runs' => Http::response(['accepted' => true], 202),
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create([
        'fulfillment_provider' => 'browser:acme',
        'package_api' => '/Customer/Category/test-pack',
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'product_api' => 'Customer/Home/ProductRequest?productId=99',
    ]);

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
        'amount_mode' => ProductAmountMode::Fixed,
        'requirements_payload' => ['id' => '987654321'],
        'status' => OrderItemStatus::Pending,
    ]);

    (new CreateFulfillmentsForOrder)->handle($order);
    $fulfillment = Fulfillment::query()->where('order_id', $order->id)->firstOrFail();

    (new DispatchFulfillmentAutomationJob($fulfillment->id))->handle(
        app(FulfillmentAutomationService::class),
        app(ReserveFulfillmentAutomationRun::class),
        app(\App\Actions\Fulfillments\StartFulfillment::class),
        app(\App\Actions\Fulfillments\DispatchFulfillmentAutomationRun::class),
    );

    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        return is_array($body)
            && ($body['package_api'] ?? null) === '/Customer/Category/test-pack'
            && ($body['product_api'] ?? null) === 'Customer/Home/ProductRequest?productId=99'
            && ($body['product_amount_mode'] ?? null) === ProductAmountMode::Fixed->value
            && ($body['requirements']['id'] ?? null) === '987654321'
            && (float) ($body['unit_price'] ?? 0) === 25.0
            && (float) ($body['line_total'] ?? 0) === 25.0;
    });
});

test('dispatch job includes custom amount and line total for custom mode items', function () {
    Http::fake([
        'http://automation-worker.test/v1/runs' => Http::response(['accepted' => true], 202),
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create([
        'fulfillment_provider' => 'browser:acme',
        'package_api' => '/Customer/Category/data-pack',
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'amount_mode' => ProductAmountMode::Custom,
        'product_api' => 'Customer/Home/ProductRequest?productId=50',
    ]);

    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 48.88,
        'fee' => 0,
        'total' => 48.88,
        'status' => OrderStatus::Paid,
    ]);

    OrderItem::create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 0.01,
        'quantity' => 1,
        'line_total' => 48.88,
        'amount_mode' => ProductAmountMode::Custom,
        'requested_amount' => 1000,
        'amount_unit_label' => 'MB',
        'requirements_payload' => ['id' => '555000111'],
        'status' => OrderItemStatus::Pending,
    ]);

    (new CreateFulfillmentsForOrder)->handle($order);
    $fulfillment = Fulfillment::query()->where('order_id', $order->id)->firstOrFail();

    (new DispatchFulfillmentAutomationJob($fulfillment->id))->handle(
        app(FulfillmentAutomationService::class),
        app(ReserveFulfillmentAutomationRun::class),
        app(\App\Actions\Fulfillments\StartFulfillment::class),
        app(\App\Actions\Fulfillments\DispatchFulfillmentAutomationRun::class),
    );

    Http::assertSent(function ($request): bool {
        $body = json_decode($request->body(), true);

        return is_array($body)
            && ($body['product_amount_mode'] ?? null) === ProductAmountMode::Custom->value
            && ($body['custom_amount']['amount'] ?? null) === 1000
            && ($body['custom_amount']['unit'] ?? null) === 'MB'
            && (float) ($body['line_total'] ?? 0) === 48.88
            && ($body['requirements']['id'] ?? null) === '555000111';
    });
});

test('ingest needs review stores product api on automation run result payload', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create(['fulfillment_provider' => 'browser:wasim']);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'product_api' => 'pubg-60uc',
    ]);

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
    $fulfillment = Fulfillment::query()->where('order_id', $order->id)->firstOrFail();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:1',
        'started_at' => now(),
        'dispatched_at' => now(),
    ]);

    $fulfillment->update(['status' => FulfillmentStatus::Processing]);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'needs_review',
        'error_code' => 'flow_incomplete',
        'message' => 'Signed in and opened Wasim product page.',
        'delivered_payload' => [
            'checkpoint' => 'product',
            'url' => 'https://wasim-store.com/pubg-60uc',
            'product_api' => 'pubg-60uc',
            'product_url' => 'https://wasim-store.com/pubg-60uc',
        ],
    ]);

    expect($run->refresh()->result_payload)->toMatchArray([
        'product_api' => 'pubg-60uc',
        'product_url' => 'https://wasim-store.com/pubg-60uc',
        'checkpoint' => 'product',
    ]);
});

test('artifact signature round trip verifies file hash', function () {
    $service = app(FulfillmentAutomationService::class);
    $file = UploadedFile::fake()->image('customer.png');
    $fileHash = hash('sha256', (string) file_get_contents($file->getRealPath() ?: ''));
    $uuid = (string) Str::uuid();
    $timestamp = time();
    $signature = $service->signArtifactPayload($uuid, 'customer', $fileHash, $timestamp);

    expect($service->verifyArtifactSignature($uuid, 'customer', $fileHash, $signature, (string) $timestamp))
        ->toBeTrue();
});

test('artifact callback accepts signed screenshot upload', function () {
    Storage::fake('local');

    $fulfillment = makeBrowserFulfillmentOrder();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:100',
        'started_at' => now(),
        'dispatched_at' => now(),
    ]);

    $file = UploadedFile::fake()->image('customer.png');
    $fileHash = hash('sha256', (string) file_get_contents($file->getRealPath() ?: ''));
    $timestamp = (string) time();
    $signature = app(FulfillmentAutomationService::class)->signArtifactPayload(
        $run->uuid,
        'customer',
        $fileHash,
        (int) $timestamp,
    );

    $this->withHeaders([
        'X-Automation-Signature' => $signature,
        'X-Automation-Timestamp' => $timestamp,
    ])->post(
        '/internal/automation/runs/'.$run->uuid.'/artifacts',
        [
            'label' => 'customer',
            'file' => $file,
        ],
    )->assertSuccessful();

    expect($run->refresh()->artifactPaths())->not->toBeEmpty();
    Storage::disk('local')->assertExists($run->refresh()->artifactPaths()[0]);
});

test('artifact upload stores screenshot path on automation run', function () {
    Storage::fake('local');

    $fulfillment = makeBrowserFulfillmentOrder();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:99',
        'started_at' => now(),
        'dispatched_at' => now(),
    ]);

    $path = app(\App\Actions\Fulfillments\StoreFulfillmentAutomationArtifact::class)->handle(
        $run,
        UploadedFile::fake()->image('customer.png'),
        'customer',
    );

    expect($run->refresh()->artifactPaths())->toContain($path);
    Storage::disk('local')->assertExists($path);
});

test('admin can view stored automation artifact by index', function () {
    Storage::fake('local');

    $admin = User::factory()->create();
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $permissions = collect(config('permission.backend_permissions', []))
        ->map(fn (string $name): Permission => Permission::firstOrCreate([
            'name' => $name,
            'guard_name' => 'web',
        ]));
    $adminRole->syncPermissions($permissions);
    $admin->assignRole($adminRole);

    $fulfillment = makeBrowserFulfillmentOrder();

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'acme',
        'status' => FulfillmentAutomationRunStatus::NeedsReview,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':attempt:artifact-view',
        'started_at' => now(),
        'dispatched_at' => now(),
    ]);

    $path = app(\App\Actions\Fulfillments\StoreFulfillmentAutomationArtifact::class)->handle(
        $run,
        UploadedFile::fake()->image('login.png'),
        'login',
    );

    $artifactUrl = $run->refresh()->artifactShowUrl(0, absolute: false);

    expect($artifactUrl)->toStartWith('/admin/fulfillment-automation/runs/')
        ->and($artifactUrl)->toContain('index=0');

    $this->actingAs($admin)
        ->get($artifactUrl)
        ->assertSuccessful()
        ->assertHeader('Content-Type', 'image/png');
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

test('ingest submitted keeps fulfillment processing and queues wasim reconcile', function () {
    $fulfillment = makeBrowserFulfillmentOrder();
    $fulfillment->update(['provider' => 'browser:wasim']);

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

    $fulfillment->update(['status' => FulfillmentStatus::Processing]);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'submitted',
        'external_order_id' => '12399',
        'delivered_payload' => [
            'supplier_entry_price' => 1.1198680372596153,
            'supplier_status' => 'Processing_OK_wait',
            'phase' => 'purchase',
        ],
    ]);

    $fulfillment->refresh();
    $run->refresh();

    expect($fulfillment->status)->toBe(FulfillmentStatus::Processing)
        ->and($run->status)->toBe(FulfillmentAutomationRunStatus::Succeeded)
        ->and(data_get($fulfillment->meta, 'automation.awaiting_wasim_reconcile'))->toBeTrue()
        ->and(data_get($fulfillment->meta, 'automation.supplier_order_id'))->toBe('12399');

    Queue::assertPushed(DispatchWasimReconcileJob::class);
});

test('ingest reconcile success completes fulfillment after wasim submission', function () {
    $fulfillment = makeBrowserFulfillmentOrder();
    $fulfillment->update([
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Processing,
        'meta' => [
            'automation' => [
                'awaiting_wasim_reconcile' => true,
                'supplier_order_id' => '12399',
                'reconcile_attempts' => 1,
            ],
        ],
    ]);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':reconcile:1',
        'dispatched_at' => now(),
        'started_at' => now(),
    ]);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'success',
        'external_order_id' => '12399',
        'delivered_payload' => [
            'phase' => 'reconcile',
            'supplier_status' => 'Completed',
            'supplier_description' => 'proof-url',
        ],
    ]);

    $fulfillment->refresh();

    expect($fulfillment->status)->toBe(FulfillmentStatus::Completed)
        ->and(data_get($fulfillment->meta, 'automation.awaiting_wasim_reconcile'))->toBeFalse()
        ->and(data_get($fulfillment->meta, 'delivered_payload.supplier_description'))->toBe('proof-url');
});

test('ingest supplier order cancelled fails fulfillment and requests refund', function () {
    $fulfillment = makeBrowserFulfillmentOrder();
    $order = Order::query()->findOrFail($fulfillment->order_id);
    Wallet::forUser(User::query()->findOrFail($order->user_id));

    $fulfillment->update([
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Processing,
        'meta' => [
            'automation' => [
                'awaiting_wasim_reconcile' => true,
                'supplier_order_id' => '12399',
            ],
        ],
    ]);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':reconcile:1',
        'dispatched_at' => now(),
        'started_at' => now(),
    ]);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'failed',
        'error_code' => 'supplier_order_cancelled',
        'message' => 'يرجى تكرار الطلب',
        'delivered_payload' => [
            'phase' => 'reconcile',
            'supplier_status' => 'Cancelled',
            'supplier_description' => 'يرجى تكرار الطلب',
        ],
    ]);

    $fulfillment->refresh();

    expect($fulfillment->status)->toBe(FulfillmentStatus::Failed)
        ->and(WalletTransaction::query()
            ->where('reference_type', Fulfillment::class)
            ->where('reference_id', $fulfillment->id)
            ->where('type', WalletTransactionType::Refund->value)
            ->exists())->toBeTrue();
});

test('ingest pending reconcile schedules another wasim reconcile attempt', function () {
    config(['fulfillment_automation.reconcile.max_attempts' => 10]);

    $fulfillment = makeBrowserFulfillmentOrder();
    $fulfillment->update([
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Processing,
        'meta' => [
            'automation' => [
                'awaiting_wasim_reconcile' => true,
                'supplier_order_id' => '12399',
                'reconcile_attempts' => 0,
            ],
        ],
    ]);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Running,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':reconcile:1',
        'dispatched_at' => now(),
        'started_at' => now(),
    ]);

    app(IngestFulfillmentAutomationResult::class)->handle($run, [
        'outcome' => 'pending_reconcile',
        'external_order_id' => '12399',
        'delivered_payload' => [
            'phase' => 'reconcile',
            'checkpoint' => 'reconcile_in_progress',
        ],
    ]);

    $fulfillment->refresh();

    expect($fulfillment->status)->toBe(FulfillmentStatus::Processing)
        ->and(data_get($fulfillment->meta, 'automation.reconcile_attempts'))->toBe(1);

    Queue::assertPushed(DispatchWasimReconcileJob::class);
});

test('reconcile worker payload includes supplier order id and reconcile phase', function () {
    $fulfillment = makeBrowserFulfillmentOrder();
    $fulfillment->update([
        'provider' => 'browser:wasim',
        'status' => FulfillmentStatus::Processing,
        'meta' => [
            'automation' => [
                'awaiting_wasim_reconcile' => true,
                'supplier_order_id' => '12399',
            ],
        ],
    ]);

    $run = FulfillmentAutomationRun::query()->create([
        'uuid' => (string) Str::uuid(),
        'fulfillment_id' => $fulfillment->id,
        'supplier_key' => 'wasim',
        'status' => FulfillmentAutomationRunStatus::Reserved,
        'attempt' => 1,
        'idempotency_key' => 'automation:fulfillment:'.$fulfillment->id.':reconcile:1',
    ]);

    $payload = app(FulfillmentAutomationService::class)->buildWorkerPayload($run, $fulfillment);

    expect($payload['automation_phase'])->toBe('reconcile')
        ->and($payload['supplier_order_id'])->toBe('12399');
});
