<?php

declare(strict_types=1);

use App\Actions\Fulfillments\CreateFulfillmentsForOrder;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderItemStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Jobs\DispatchFulfillmentAutomationJob;
use App\Models\Fulfillment;
use App\Models\MobileCheckoutAttempt;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\PackageRequirement;
use App\Models\Product;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    m31Website();
    m31EnsurePricingRule();
    RateLimiter::clear('mobile-purchase-write-user|1');
});

test('distinct idempotency keys allow intentional repurchase of the same product within five minutes', function (): void {
    config(['billing.checkout_paid_idempotency_minutes' => 5]);

    $user = m31Customer();
    m31Fund($user, 200);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    $first = m31CheckoutOnce($user, $token, $product, $package, 'repurchase-key-a');
    $second = m31CheckoutOnce($user, $token, $product, $package, 'repurchase-key-b');

    expect($first['order_number'])->not->toBe($second['order_number'])
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(2)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(2)
        ->and(Fulfillment::query()->count())->toBe(2);
});

test('checkout and status share idempotency key validation bounds without leaking the raw key', function (): void {
    $user = m31Customer();
    $token = m31Token($user);
    $oversized = str_repeat('k', 129);

    $checkout = $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => 1, 'package_id' => 1, 'quantity' => 1]],
        'quote_fingerprint' => str_repeat('a', 32),
    ], m31Headers($token, $oversized));

    $checkout->assertStatus(422)->assertJsonPath('code', 'idempotency_key_invalid');
    expect(json_encode($checkout->json()))->not->toContain($oversized);

    $status = $this->getJson('/api/v1/checkout/status', m31Headers($token, $oversized));
    $status->assertStatus(422)->assertJsonPath('code', 'idempotency_key_invalid');
    expect(json_encode($status->json()))->not->toContain($oversized);

    $this->getJson('/api/v1/checkout/status', m31Headers($token))
        ->assertStatus(422)
        ->assertJsonPath('code', 'idempotency_key_required');
});

test('oversized requirement labels and option sets fail closed without leaking rules', function (): void {
    $user = m31Customer();
    ['package' => $package] = m31FixedProduct();
    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'bad_label',
        'label' => str_repeat('L', 200),
        'type' => 'string',
        'is_required' => true,
        'validation_rules' => 'required|string|regex:/secret-rule/',
        'order' => 1,
    ]);
    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'choice',
        'label' => 'Choice',
        'type' => 'select',
        'is_required' => true,
        'validation_rules' => 'required|in:'.implode(',', array_map(fn ($i) => 'opt'.$i, range(1, 40))),
        'order' => 2,
    ]);
    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'ok_field',
        'label' => 'OK',
        'type' => 'string',
        'is_required' => true,
        'validation_rules' => 'required|string|max:16',
        'order' => 3,
    ]);

    $token = m31Token($user);
    $response = $this->getJson('/api/v1/packages/'.$package->id, m31Headers($token))->assertOk();

    $keys = collect($response->json('data.requirements'))->pluck('key')->all();
    expect($keys)->toContain('ok_field')
        ->and($keys)->not->toContain('bad_label');

    $choice = collect($response->json('data.requirements'))->firstWhere('key', 'choice');
    if ($choice !== null) {
        expect($choice['input_type'])->toBe('text')
            ->and($choice['options'])->toBeNull();
    }

    expect(json_encode($response->json()))->not->toContain('secret-rule')
        ->and(json_encode($response->json()))->not->toContain('regex');
});

test('automation dispatch failure is isolated and queued fulfillment remains command-recoverable', function (): void {
    WebsiteSetting::query()->update(['automation_enabled' => true]);
    config([
        'fulfillment_automation.enabled' => true,
        'fulfillment_automation.callback_secret' => 'test-automation-secret',
        'fulfillment_automation.worker_url' => 'http://automation-worker.test',
    ]);

    $user = m31Customer();
    $package = Package::factory()->create([
        'is_active' => true,
        'fulfillment_provider' => 'browser:acme',
    ]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'is_active' => true,
        'entry_price' => 10,
    ]);

    $order = Order::query()->create([
        'user_id' => $user->id,
        'order_number' => Order::temporaryOrderNumber(),
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
        'paid_at' => now(),
    ]);
    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'package_id' => $package->id,
        'name' => $product->name,
        'unit_price' => 10,
        'quantity' => 1,
        'line_total' => 10,
        'status' => OrderItemStatus::Pending,
    ]);

    Log::spy();

    $dispatcher = Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')->andThrow(new RuntimeException('queue broker unavailable'));
    $dispatcher->shouldReceive('dispatchSync')->andThrow(new RuntimeException('queue broker unavailable'));
    $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);

    expect(fn () => (new CreateFulfillmentsForOrder)->handle($order->fresh(['items'])))
        ->not->toThrow(RuntimeException::class);

    $fulfillment = Fulfillment::query()->where('order_id', $order->id)->firstOrFail();
    expect($fulfillment->status)->toBe(FulfillmentStatus::Queued);

    Log::shouldHaveReceived('warning')->withArgs(function (...$args): bool {
        $message = (string) ($args[0] ?? '');
        $context = is_array($args[1] ?? null) ? $args[1] : [];

        return str_contains($message, 'automation dispatch failed')
            && ($context['error_id'] ?? null) === 'fulfillment_automation_dispatch_failed'
            && ($context['recoverable_via'] ?? null) === 'fulfillment:dispatch-automation';
    })->atLeast()->once();

    // Restore real bus/queue and prove the durable Queued row is command-dispatchable.
    $this->app->forgetInstance(\Illuminate\Contracts\Bus\Dispatcher::class);
    Bus::clearResolvedInstances();
    Queue::fake();

    $this->artisan('fulfillment:dispatch-automation')->assertSuccessful();

    Queue::assertPushed(DispatchFulfillmentAutomationJob::class, function (DispatchFulfillmentAutomationJob $job) use ($fulfillment): bool {
        return $job->fulfillmentId === (int) $fulfillment->id;
    });
});

test('sqlite-compatible claim race resolves without 500 for same key', function (): void {
    $user = m31Customer();
    m31Fund($user, 200);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'sqlite-race-'.uniqid();

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    $payload = [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ];

    $first = $this->postJson('/api/v1/checkout', $payload, m31Headers($token, $key))->assertOk();
    $second = $this->postJson('/api/v1/checkout', $payload, m31Headers($token, $key))->assertOk();

    expect($first->json('data.order.order_number'))->toBe($second->json('data.order.order_number'))
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(1);

    $otherKey = 'sqlite-in-progress-'.uniqid();
    MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => hash('sha256', $otherKey),
        'request_hash' => hash('sha256', json_encode([
            'item' => [
                'product_id' => (int) $product->id,
                'package_id' => (int) $package->id,
                'quantity' => 1,
                'requested_amount' => null,
                'requirements' => [],
            ],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
        'status' => \App\Enums\MobileCheckoutAttemptStatus::Processing,
        'processing_started_at' => now(),
    ]);

    $quote2 = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $quote2->json('data.quote_fingerprint'),
    ], m31Headers($token, $otherKey))
        ->assertStatus(202)
        ->assertJsonPath('code', 'checkout_in_progress');
});
