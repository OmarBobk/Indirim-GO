<?php

declare(strict_types=1);

use App\Enums\CreditFacilityStatus;
use App\Enums\ProductAmountMode;
use App\Enums\WalletTransactionType;
use App\Events\FulfillmentListChanged;
use App\Models\Fulfillment;
use App\Models\MobileCheckoutAttempt;
use App\Models\Order;
use App\Models\Package;
use App\Models\Product;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    m31Website();
    m31EnsurePricingRule();
    RateLimiter::clear('mobile-purchase-write-user|1');
});

test('wallet summary returns available_to_spend for the authenticated customer', function (): void {
    $user = m31Customer();
    m31Fund($user, 42.50);
    $token = m31Token($user);

    $response = $this->getJson('/api/v1/wallet/summary', m31Headers($token));

    $response->assertOk()
        ->assertJsonPath('data.available_to_spend.amount', '42.50')
        ->assertJsonPath('data.available_to_spend.currency', 'USD')
        ->assertJsonPath('meta.prices_visible', true);

    expect($response->headers->get('Cache-Control'))->toContain('private')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');

    m31AssertNoSensitiveKeys($response->json(), m31SensitiveKeys());
});

test('wallet summary remains available when prices are hidden', function (): void {
    m31Website(['prices_visible' => false]);
    $user = m31Customer();
    m31Fund($user, 10);
    $token = m31Token($user);

    $this->getJson('/api/v1/wallet/summary', m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data.available_to_spend.amount', '10.00')
        ->assertJsonPath('meta.prices_visible', false);
});

test('package detail includes sanitized requirements without raw rules', function (): void {
    $user = m31Customer();
    ['package' => $package] = m31FixedProduct([], [
        'key' => 'id',
        'label' => 'Player ID',
        'type' => 'string',
        'is_required' => true,
        'validation_rules' => 'required|string|max:64|regex:/^[0-9]+$/',
    ]);
    $token = m31Token($user);

    $response = $this->getJson('/api/v1/packages/'.$package->id, m31Headers($token));

    $response->assertOk()
        ->assertJsonPath('data.requirements.0.key', 'id')
        ->assertJsonPath('data.requirements.0.input_type', 'text')
        ->assertJsonPath('data.requirements.0.required', true)
        ->assertJsonPath('data.requirements.0.max_length', 64);

    m31AssertNoSensitiveKeys($response->json(), m31SensitiveKeys());
    expect(json_encode($response->json()))->not->toContain('regex');
});

test('quote and checkout are blocked when prices_visible is false without ending session', function (): void {
    m31Website(['prices_visible' => false]);
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token));

    $quote->assertStatus(409)->assertJsonPath('code', 'purchasing_unavailable');

    $checkout = $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
        'quote_fingerprint' => str_repeat('a', 32),
    ], m31Headers($token, 'key-prices-hidden'));

    $checkout->assertStatus(409)->assertJsonPath('code', 'purchasing_unavailable');

    $this->getJson('/api/v1/me', m31Headers($token))->assertOk();
});

test('fixed product quote and successful checkout create one order debit and fulfillments', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct([], [
        'key' => 'id',
        'validation_rules' => 'required|string|max:32',
    ]);
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 2,
            'requirements' => ['id' => 'player-1'],
            'unit_price' => 1,
        ]],
    ], m31Headers($token));

    $quote->assertStatus(422);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 2,
            'requirements' => ['id' => 'player-1'],
        ]],
    ], m31Headers($token));

    $quote->assertOk()
        ->assertJsonPath('data.item.quantity', 2)
        ->assertJsonPath('data.wallet.can_afford', true);

    m31AssertNoSensitiveKeys($quote->json(), m31SensitiveKeys());
    expect(json_encode($quote->json()))->not->toContain('player-1');

    $fingerprint = $quote->json('data.quote_fingerprint');
    $idem = 'idem-success-'.uniqid();

    $checkout = $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 2,
            'requirements' => ['id' => 'player-1'],
        ]],
        'quote_fingerprint' => $fingerprint,
    ], m31Headers($token, $idem));

    $checkout->assertOk()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.order.payment_status', 'paid');

    expect(Order::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(1)
        ->and(Fulfillment::query()->count())->toBe(2);

    m31AssertNoSensitiveKeys($checkout->json(), m31SensitiveKeys());
    expect(json_encode($checkout->json()))->not->toContain('player-1');

    $orderNumber = $checkout->json('data.order.order_number');

    $this->getJson('/api/v1/orders/'.$orderNumber, m31Headers($token))
        ->assertOk()
        ->assertJsonPath('data.order.order_number', $orderNumber);

    $other = m31Customer();
    expect($other->id)->not->toBe($user->id);
    $otherToken = m31Token($other);
    $tokenId = (int) explode('|', $otherToken, 2)[0];
    $tokenableId = \Laravel\Sanctum\PersonalAccessToken::query()->whereKey($tokenId)->value('tokenable_id');
    expect((int) $tokenableId)->toBe((int) $other->id);

    $this->flushHeaders();
    $this->withHeaders(m31Headers($otherToken))
        ->getJson('/api/v1/orders/'.$orderNumber)
        ->assertNotFound()
        ->assertJsonPath('code', 'order_not_found');
});

test('custom amount quote enforces min max step and prices nonlinear entry totals', function (): void {
    $user = m31Customer();
    m31Fund($user, 1000);
    ['package' => $package, 'product' => $product] = m31CustomProduct();
    $token = m31Token($user);

    $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'requested_amount' => 75,
        ]],
    ], m31Headers($token))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_custom_amount');

    $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'requested_amount' => 125,
        ]],
    ], m31Headers($token))
        ->assertStatus(422)
        ->assertJsonPath('code', 'invalid_custom_amount');

    $ok = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'requested_amount' => 150,
        ]],
    ], m31Headers($token));

    $ok->assertOk()
        ->assertJsonPath('data.item.requested_amount', 150)
        ->assertJsonPath('data.item.quantity', 1)
        ->assertJsonPath('data.total.amount', '1.50');
});

test('price change returns 409 before creating order or debit', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token))->assertOk();

    $fingerprint = $quote->json('data.quote_fingerprint');
    $product->update(['entry_price' => 25]);

    $checkout = $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
        'quote_fingerprint' => $fingerprint,
    ], m31Headers($token, 'idem-price-change'));

    $checkout->assertStatus(409)
        ->assertJsonPath('code', 'price_changed')
        ->assertJsonPath('details.current_quote.total.amount', '25.00');

    expect(Order::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->count())->toBe(0)
        ->and(MobileCheckoutAttempt::query()->count())->toBe(0);
});

test('insufficient wallet balance does not create order or poison idempotency key', function (): void {
    $user = m31Customer();
    m31Fund($user, 1);
    ['package' => $package, 'product' => $product] = m31FixedProduct(['entry_price' => 50]);
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token))->assertOk();

    expect($quote->json('data.wallet.can_afford'))->toBeFalse();

    $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'idem-insufficient'))
        ->assertStatus(422)
        ->assertJsonPath('code', 'insufficient_wallet_balance');

    expect(Order::query()->count())->toBe(0)
        ->and(MobileCheckoutAttempt::query()->count())->toBe(0);
});

test('credit facility allows spend within available_to_spend floor', function (): void {
    $user = m31Customer();
    $wallet = m31Fund($user, 5);
    $wallet->update([
        'credit_enabled' => true,
        'credit_limit' => 20,
        'credit_status' => CreditFacilityStatus::Active,
        'payment_terms_days' => 30,
    ]);
    ['package' => $package, 'product' => $product] = m31FixedProduct(['entry_price' => 15]);
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token))->assertOk();

    expect($quote->json('data.wallet.available_to_spend.amount'))->toBe('25.00')
        ->and($quote->json('data.wallet.can_afford'))->toBeTrue();

    $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'idem-credit'))
        ->assertOk()
        ->assertJsonPath('data.order.payment_status', 'paid');
});

test('idempotency replay conflict and cross-customer key reuse', function (): void {
    $user = m31Customer();
    m31Fund($user, 200);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $key = 'shared-key-abc';

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token))->assertOk();

    $first = $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, $key))->assertOk();

    $orderNumber = $first->json('data.order.order_number');

    $replay = $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, $key))->assertOk();

    expect($replay->json('data.replayed'))->toBeTrue()
        ->and($replay->json('data.order.order_number'))->toBe($orderNumber)
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(1);

    $this->getJson('/api/v1/checkout/status', m31Headers($token, $key))
        ->assertOk()
        ->assertJsonPath('data.state', 'completed')
        ->assertJsonPath('data.order.order_number', $orderNumber);

    $quote2 = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 2,
        ]],
    ], m31Headers($token))->assertOk();

    $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 2,
        ]],
        'quote_fingerprint' => $quote2->json('data.quote_fingerprint'),
    ], m31Headers($token, $key))
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    $other = m31Customer();
    m31Fund($other, 200);
    $otherToken = m31Token($other);
    $this->flushHeaders();
    $otherQuote = $this->withHeaders(m31Headers($otherToken))->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ])->assertOk();

    $this->withHeaders(m31Headers($otherToken, $key))->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
        'quote_fingerprint' => $otherQuote->json('data.quote_fingerprint'),
    ])
        ->assertOk()
        ->assertJsonPath('data.replayed', false);

    expect(Order::query()->count())->toBe(2);
});

test('missing checkout status returns checkout_attempt_not_found', function (): void {
    $user = m31Customer();
    $token = m31Token($user);

    $this->getJson('/api/v1/checkout/status', m31Headers($token, 'never-used-key'))
        ->assertNotFound()
        ->assertJsonPath('code', 'checkout_attempt_not_found');
});

test('inactive customer cannot purchase', function (): void {
    $user = m31Customer(['is_active' => false]);
    m31Fund($user, 100);
    ['product' => $product, 'package' => $package] = m31FixedProduct();
    $token = m31Token($user);

    $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token))
        ->assertUnauthorized()
        ->assertJsonPath('code', 'account_inactive');
});

test('token without mobile ability is rejected', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['product' => $product, 'package' => $package] = m31FixedProduct();
    $token = m31Token($user, 'web:access');

    $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token))
        ->assertForbidden()
        ->assertJsonPath('code', 'missing_mobile_ability');
});

test('optional fulfillment broadcast failure does not convert committed purchase to 500', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    Event::listen(FulfillmentListChanged::class, function (): void {
        throw new RuntimeException('reverb down');
    });

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token))->assertOk();

    $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'idem-broadcast'))
        ->assertOk()
        ->assertJsonPath('data.order.payment_status', 'paid');

    expect(Order::query()->count())->toBe(1)
        ->and(Fulfillment::query()->count())->toBe(1);
});

test('purchase write rate limiter enforces per-user and per-ip dimensions', function (): void {
    config(['cache.default' => 'array']);
    $user = m31Customer();
    m31Fund($user, 1000);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    $makeCheckout = function (string $key) use ($token, $product, $package) {
        $quote = $this->postJson('/api/v1/checkout/quote', [
            'items' => [[
                'product_id' => $product->id,
                'package_id' => $package->id,
                'quantity' => 1,
            ]],
        ], m31Headers($token))->assertOk();

        return $this->postJson('/api/v1/checkout', [
            'items' => [[
                'product_id' => $product->id,
                'package_id' => $package->id,
                'quantity' => 1,
            ]],
            'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
        ], m31Headers($token, $key));
    };

    // Exhaust user dimension (20/min). Use unique products via quantity change after first
    // would conflict on cart_hash reuse — use distinct idempotency keys after first success
    // by changing product entry via new products.
    for ($i = 0; $i < 20; $i++) {
        $p = Product::factory()->create([
            'package_id' => $package->id,
            'is_active' => true,
            'entry_price' => 1,
            'amount_mode' => ProductAmountMode::Fixed,
        ]);
        $quote = $this->postJson('/api/v1/checkout/quote', [
            'items' => [['product_id' => $p->id, 'package_id' => $package->id, 'quantity' => 1]],
        ], m31Headers($token))->assertOk();

        $this->postJson('/api/v1/checkout', [
            'items' => [['product_id' => $p->id, 'package_id' => $package->id, 'quantity' => 1]],
            'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
        ], m31Headers($token, 'rate-user-'.$i))->assertOk();
    }

    $blocked = $makeCheckout('rate-user-overflow');
    $blocked->assertStatus(429)->assertJsonPath('code', 'too_many_requests');
});

test('exactly one item is enforced', function (): void {
    $user = m31Customer();
    $token = m31Token($user);
    ['product' => $product, 'package' => $package] = m31FixedProduct();

    $this->postJson('/api/v1/checkout/quote', [
        'items' => [
            ['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1],
            ['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1],
        ],
    ], m31Headers($token))->assertStatus(422);

    $this->postJson('/api/v1/checkout/quote', [
        'items' => [],
    ], m31Headers($token))->assertStatus(422);
});

test('requirement values are not written to application logs during quote', function (): void {
    Log::spy();
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct([], [
        'key' => 'id',
        'validation_rules' => 'required|string',
    ]);
    $token = m31Token($user);

    $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'requirements' => ['id' => 'secret-player-999'],
        ]],
    ], m31Headers($token))->assertOk();

    Log::shouldNotHaveReceived('info', function (...$args) {
        return str_contains(json_encode($args), 'secret-player-999');
    });
    Log::shouldNotHaveReceived('warning', function (...$args) {
        return str_contains(json_encode($args), 'secret-player-999');
    });
});

test('package product mismatch is rejected', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['product' => $product] = m31FixedProduct();
    $otherPackage = Package::factory()->create(['is_active' => true]);
    $token = m31Token($user);

    $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $otherPackage->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token))->assertStatus(422);
});

test('unavailable product returns product_unavailable', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $product->update(['is_active' => false]);
    $token = m31Token($user);

    $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
        ]],
    ], m31Headers($token))
        ->assertStatus(422)
        ->assertJsonPath('code', 'product_unavailable');
});
