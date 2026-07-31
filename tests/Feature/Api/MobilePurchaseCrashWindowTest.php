<?php

declare(strict_types=1);

use App\Actions\Orders\CheckoutFromPayload;
use App\Enums\MobileCheckoutAttemptStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\MobileCheckoutAttempt;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Support\Api\V1\MobileCheckoutIdempotency;
use App\Support\Api\V1\MobilePurchaseReceiptFactory;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    m31Website();
    m31EnsurePricingRule();
    RateLimiter::clear('mobile-purchase-write-user|1');
});

test('retry after simulated post-purchase crash before markCompleted replays one paid order', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'crash-before-complete-'.uniqid();
    $keyHash = app(MobileCheckoutIdempotency::class)->hashKey($key);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    // Simulate the old crash window: purchase committed, attempt still processing, no order_id.
    $checkout = app(CheckoutFromPayload::class)->handle(
        $user,
        [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        [
            'source' => 'mobile_api',
            'mobile_attempt_key_hash' => $keyHash,
        ],
    );

    MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => $keyHash,
        'request_hash' => app(MobileCheckoutIdempotency::class)->hashRequest([
            'item' => [
                'product_id' => (int) $product->id,
                'package_id' => (int) $package->id,
                'quantity' => 1,
                'requested_amount' => null,
                'requirements' => [],
            ],
        ]),
        'status' => MobileCheckoutAttemptStatus::Processing,
        'processing_started_at' => now()->subMinutes(10),
        'order_id' => null,
        'receipt' => null,
    ]);

    expect($checkout->order->status)->toBe(OrderStatus::Paid)
        ->and(Order::query()->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(1);

    $retry = $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, $key))->assertOk();

    expect($retry->json('data.replayed'))->toBeTrue()
        ->and($retry->json('data.order.order_number'))->toBe($checkout->order->order_number);

    m31AssertSinglePurchase($user, $checkout->order->order_number);
});

test('status reconciles linked paid order with missing receipt snapshot', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'missing-receipt-'.uniqid();

    $first = m31CheckoutOnce($user, $token, $product, $package, $key);
    $orderNumber = $first['order_number'];

    $attempt = MobileCheckoutAttempt::query()->where('user_id', $user->id)->firstOrFail();
    $attempt->update([
        'receipt' => null,
        'status' => MobileCheckoutAttemptStatus::Completed,
        'order_id' => Order::query()->where('order_number', $orderNumber)->value('id'),
    ]);

    $status = $this->getJson('/api/v1/checkout/status', m31Headers($token, $key))
        ->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('data.order.order_number', $orderNumber);

    expect($status->json('data.order.payment_status'))->toBe('paid');
    m31AssertSinglePurchase($user, $orderNumber);
});

test('stale processing takeover never clears linked paid order and replays receipt', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'stale-linked-'.uniqid();

    $first = m31CheckoutOnce($user, $token, $product, $package, $key);
    $order = Order::query()->where('order_number', $first['order_number'])->firstOrFail();

    $attempt = MobileCheckoutAttempt::query()->where('user_id', $user->id)->firstOrFail();
    $attempt->update([
        'status' => MobileCheckoutAttemptStatus::Processing,
        'processing_started_at' => now()->subMinutes(10),
        'completed_at' => null,
        'receipt' => null,
        'order_id' => $order->id,
    ]);

    $retry = $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $first['quote_fingerprint'],
    ], m31Headers($token, $key))->assertOk();

    expect($retry->json('data.order.order_number'))->toBe($order->order_number)
        ->and($attempt->fresh()->order_id)->toBe($order->id)
        ->and($attempt->fresh()->status)->toBe(MobileCheckoutAttemptStatus::Completed);

    m31AssertSinglePurchase($user, $order->order_number);
});

test('retry after stale timeout and beyond five-minute web reuse still replays one purchase', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'stale-beyond-web-'.uniqid();

    $first = m31CheckoutOnce($user, $token, $product, $package, $key);
    $order = Order::query()->where('order_number', $first['order_number'])->firstOrFail();

    $order->update(['paid_at' => now()->subMinutes(30)]);
    MobileCheckoutAttempt::query()->where('user_id', $user->id)->update([
        'status' => MobileCheckoutAttemptStatus::Processing,
        'processing_started_at' => now()->subMinutes(10),
        'completed_at' => null,
        'receipt' => null,
        // Keep durable linkage — takeover must not clear it.
        'order_id' => $order->id,
    ]);

    config(['billing.checkout_paid_idempotency_minutes' => 5]);

    $retry = $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $first['quote_fingerprint'],
    ], m31Headers($token, $key))->assertOk();

    expect($retry->json('data.order.order_number'))->toBe($order->order_number);
    m31AssertSinglePurchase($user, $order->order_number);
});

test('failed receipt serialization after durable commit still leaves one recoverable purchase', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'receipt-fail-'.uniqid();

    $first = m31CheckoutOnce($user, $token, $product, $package, $key);
    $orderNumber = $first['order_number'];

    // Simulate snapshot failure after durable linkage committed.
    $attempt = MobileCheckoutAttempt::query()->where('user_id', $user->id)->firstOrFail();
    $attempt->update([
        'receipt' => null,
        'status' => MobileCheckoutAttemptStatus::Completed,
        'order_id' => Order::query()->where('order_number', $orderNumber)->value('id'),
    ]);

    $retry = $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $first['quote_fingerprint'],
    ], m31Headers($token, $key))->assertOk();

    expect($retry->json('data.order.order_number'))->toBe($orderNumber);

    $status = $this->getJson('/api/v1/checkout/status', m31Headers($token, $key))
        ->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('data.order.order_number', $orderNumber);

    expect($status->json('data.order.payment_status'))->toBe('paid');
    m31AssertSinglePurchase($user, $orderNumber);
});

test('exception after commit does not release an attempt already linked to a paid order', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'post-commit-exc-'.uniqid();

    $first = m31CheckoutOnce($user, $token, $product, $package, $key);
    $attempt = MobileCheckoutAttempt::query()->where('user_id', $user->id)->firstOrFail();
    $orderId = $attempt->order_id;

    app(MobileCheckoutIdempotency::class)->releaseIfSafe(
        $attempt,
        $user,
        fn ($order, $owner) => app(MobilePurchaseReceiptFactory::class)->fromOrder($order, $owner),
    );

    expect(MobileCheckoutAttempt::query()->whereKey($attempt->id)->exists())->toBeTrue()
        ->and($attempt->fresh()->order_id)->toBe($orderId)
        ->and($attempt->fresh()->status)->toBe(MobileCheckoutAttemptStatus::Completed);

    m31AssertSinglePurchase($user, $first['order_number']);
});

test('lost http response is recovered by status polling of durable completed attempt', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'lost-http-'.uniqid();

    $first = m31CheckoutOnce($user, $token, $product, $package, $key);

    $status = $this->getJson('/api/v1/checkout/status', m31Headers($token, $key))
        ->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('data.order.order_number', $first['order_number']);

    expect($status->json('data.order.payment_status'))->toBe('paid')
        ->and(Fulfillment::query()->count())->toBe(1);

    m31AssertSinglePurchase($user, $first['order_number']);
});

test('status stale unlinked orphan returns checkout_retry_required and same key may retry', function (): void {
    config(['mobile_api.checkout.processing_stale_seconds' => 15]);

    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'stale-orphan-status-'.uniqid();
    $keyHash = app(MobileCheckoutIdempotency::class)->hashKey($key);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => $keyHash,
        'request_hash' => app(MobileCheckoutIdempotency::class)->hashRequest([
            'item' => [
                'product_id' => (int) $product->id,
                'package_id' => (int) $package->id,
                'quantity' => 1,
                'requested_amount' => null,
                'requirements' => [],
            ],
        ]),
        'status' => MobileCheckoutAttemptStatus::Processing,
        'processing_started_at' => now()->subMinutes(10),
        'order_id' => null,
        'receipt' => null,
    ]);

    expect(Order::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(0);

    $this->getJson('/api/v1/checkout/status', m31Headers($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('code', 'checkout_retry_required')
        ->assertJsonPath('details.action', 'resubmit_identical_checkout')
        ->assertJsonPath('details.idempotency_key_policy', 'reuse_same_key');

    $attempt = MobileCheckoutAttempt::query()->where('user_id', $user->id)->where('key_hash', $keyHash)->firstOrFail();
    expect($attempt->status)->toBe(MobileCheckoutAttemptStatus::Failed)
        ->and($attempt->failure_code)->toBe('checkout_retry_required')
        ->and($attempt->order_id)->toBeNull();

    $retry = $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, $key))->assertOk();

    expect($retry->json('data.replayed'))->toBeFalse();
    m31AssertSinglePurchase($user, (string) $retry->json('data.order.order_number'));
});

test('status stale orphan with different payload still conflicts after retry_required', function (): void {
    config(['mobile_api.checkout.processing_stale_seconds' => 15]);

    $user = m31Customer();
    m31Fund($user, 200);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $other = m31FixedProduct(['name' => 'Other Fixed '.uniqid()]);
    $token = m31Token($user);
    $key = 'stale-orphan-conflict-'.uniqid();
    $keyHash = app(MobileCheckoutIdempotency::class)->hashKey($key);

    MobileCheckoutAttempt::query()->create([
        'user_id' => $user->id,
        'key_hash' => $keyHash,
        'request_hash' => app(MobileCheckoutIdempotency::class)->hashRequest([
            'item' => [
                'product_id' => (int) $product->id,
                'package_id' => (int) $package->id,
                'quantity' => 1,
                'requested_amount' => null,
                'requirements' => [],
            ],
        ]),
        'status' => MobileCheckoutAttemptStatus::Processing,
        'processing_started_at' => now()->subMinutes(10),
        'order_id' => null,
    ]);

    $this->getJson('/api/v1/checkout/status', m31Headers($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('code', 'checkout_retry_required');

    $otherQuote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $other['product']->id, 'package_id' => $other['package']->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $other['product']->id, 'package_id' => $other['package']->id, 'quantity' => 1]],
        'quote_fingerprint' => $otherQuote->json('data.quote_fingerprint'),
    ], m31Headers($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    expect(Order::query()->where('user_id', $user->id)->count())->toBe(0);
});

test('fresh quote fingerprint uses ledger decimals without float sprintf bridge', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct(['entry_price' => 10]);
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    expect($quote->json('data.total.amount'))->toBe('10.00')
        ->and($quote->json('data.item.line_total.amount'))->toBe('10.00')
        ->and($quote->json('data.item.unit_price.amount'))->toBe('10.00');

    $dto = app(\App\Domain\Pricing\PricingEngine::class)->quote($product->fresh(), 1, null, $user);
    expect($dto->finalTotalDecimal)->toBe('10.00')
        ->and($dto->unitPriceDecimal)->toStartWith('10.0');
});
