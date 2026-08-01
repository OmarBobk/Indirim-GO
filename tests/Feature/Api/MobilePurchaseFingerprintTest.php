<?php

declare(strict_types=1);

use App\Models\MobileCheckoutAttempt;
use App\Models\Order;
use App\Models\PackageRequirement;
use App\Models\PricingRule;
use App\Models\WalletTransaction;
use App\Support\Api\V1\MobileCheckoutQuoteBuilder;
use App\Support\Api\V1\MobileMoneyFactory;
use App\Support\LedgerMoney;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    m31Website();
    m31EnsurePricingRule();
    RateLimiter::clear('mobile-purchase-write-user|1');
});

function m31AssertNoPurchaseSideEffects(): void
{
    expect(Order::query()->count())->toBe(0)
        ->and(WalletTransaction::query()->count())->toBe(0)
        ->and(MobileCheckoutAttempt::query()->count())->toBe(0);
}

test('expired fingerprint is rejected before order or debit', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    $this->travel(10)->minutes();

    $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'fp-expired'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed');

    m31AssertNoPurchaseSideEffects();
});

test('modified quantity fingerprint mismatch occurs before debit', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 2]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'fp-qty'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed');

    m31AssertNoPurchaseSideEffects();
});

test('modified custom amount fingerprint mismatch occurs before debit', function (): void {
    $user = m31Customer();
    m31Fund($user, 1000);
    ['package' => $package, 'product' => $product] = m31CustomProduct();
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'requested_amount' => 150]],
    ], m31Headers($token))->assertOk();

    $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'requested_amount' => 200]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'fp-custom'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed');

    m31AssertNoPurchaseSideEffects();
});

test('modified requirements fingerprint mismatch occurs before debit', function (): void {
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
            'quantity' => 1,
            'requirements' => ['id' => 'player-a'],
        ]],
    ], m31Headers($token))->assertOk();

    $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'requirements' => ['id' => 'player-b'],
        ]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'fp-req'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed');

    m31AssertNoPurchaseSideEffects();
});

test('modified product fingerprint mismatch occurs before debit', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $other = m31FixedProduct(['entry_price' => 10]);
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $other['product']->id, 'package_id' => $other['package']->id, 'quantity' => 1]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'fp-product'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed');

    m31AssertNoPurchaseSideEffects();
});

test('cross-customer fingerprint is rejected before debit', function (): void {
    $user = m31Customer();
    $other = m31Customer();
    m31Fund($user, 100);
    m31Fund($other, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);
    $otherToken = m31Token($other);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    $this->flushHeaders();
    $this->withHeaders(m31Headers($otherToken, 'fp-cross'))->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ])
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed');

    m31AssertNoPurchaseSideEffects();
});

test('tampered fingerprint signature is rejected before debit', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    $fingerprint = (string) $quote->json('data.quote_fingerprint');
    $parts = explode('.', $fingerprint);
    $parts[1] = strrev($parts[1]);
    $tampered = implode('.', $parts);

    $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $tampered,
    ], m31Headers($token, 'fp-tamper'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed');

    m31AssertNoPurchaseSideEffects();
});

test('requirements with different json key order but identical semantics still match', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'server',
        'label' => 'Server',
        'type' => 'string',
        'is_required' => true,
        'validation_rules' => 'required|string|max:32',
        'order' => 1,
    ]);
    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'id',
        'label' => 'Player ID',
        'type' => 'string',
        'is_required' => true,
        'validation_rules' => 'required|string|max:32',
        'order' => 2,
    ]);
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'requirements' => ['id' => 'p1', 'server' => 'eu'],
        ]],
    ], m31Headers($token))->assertOk();

    $this->postJson('/api/v1/checkout', [
        'items' => [[
            'product_id' => $product->id,
            'package_id' => $package->id,
            'quantity' => 1,
            'requirements' => ['server' => 'eu', 'id' => 'p1'],
        ]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'fp-req-order'))
        ->assertOk()
        ->assertJsonPath('data.order.payment_status', 'paid');
});

test('loyalty or pricing rule change invalidates fingerprint before debit', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct(['entry_price' => 10]);
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    PricingRule::query()->update(['retail_percentage' => 50]);

    $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'fp-pricing'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'price_changed');

    m31AssertNoPurchaseSideEffects();
});

test('prices_visible flipping after quote blocks checkout before debit', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    ['package' => $package, 'product' => $product] = m31FixedProduct();
    $token = m31Token($user);

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    m31Website(['prices_visible' => false]);

    $this->postJson('/api/v1/checkout', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
        'quote_fingerprint' => $quote->json('data.quote_fingerprint'),
    ], m31Headers($token, 'fp-hidden'))
        ->assertStatus(409)
        ->assertJsonPath('code', 'purchasing_unavailable');

    m31AssertNoPurchaseSideEffects();
});

test('mobile money factory preserves decimal strings unsafe through binary floats', function (): void {
    $user = m31Customer();
    m31Fund($user, 100);
    $factory = MobileMoneyFactory::forUser($user);

    // 0.1 + 0.2 is the classic binary float trap; ledger path must stay "0.30".
    $sum = LedgerMoney::add('0.10', '0.20');
    expect($sum)->toBe('0.30')
        ->and($factory->fromUsdDecimal($sum)['amount'])->toBe('0.30');

    // Values that are exact as decimal strings must not be re-cast through float envelopes.
    expect($factory->fromUsdDecimal('0.01')['amount'])->toBe('0.01')
        ->and($factory->fromUsdDecimal('12345678.91')['amount'])->toBe('12345678.91');

    ['product' => $product] = m31FixedProduct(['entry_price' => 0.1]);
    $builder = app(MobileCheckoutQuoteBuilder::class);
    $quote = $builder->build($user, [
        'product_id' => (int) $product->id,
        'package_id' => null,
        'quantity' => 3,
        'requested_amount' => null,
        'requirements' => [],
    ]);

    expect($quote['total']['amount'])->toBe(LedgerMoney::normalize((string) $quote['_authoritative_total']))
        ->and($quote['item']['line_total']['amount'])->toBe($quote['subtotal']['amount']);
});
