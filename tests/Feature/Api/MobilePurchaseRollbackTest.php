<?php

declare(strict_types=1);

use App\Actions\MobilePurchase\ExecuteMobileCheckout;
use App\Enums\MobileCheckoutAttemptStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\MobileCheckoutAttempt;
use App\Models\Order;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Api\V1\MobileCheckoutCommitGate;
use App\Support\LedgerMoney;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function (): void {
    m31Website();
    m31EnsurePricingRule();
    RateLimiter::clear('mobile-purchase-write-user|1');
});

test('authoritative mobile checkout rolls back debit order fulfillments and attempt when gate throws before complete', function (): void {
    $user = m31Customer();
    $wallet = m31Fund($user, '100.00');
    $openingBalance = LedgerMoney::normalize((string) $wallet->fresh()->balance);
    ['package' => $package, 'product' => $product] = m31FixedProduct(['entry_price' => 10]);
    $token = m31Token($user);
    $key = 'rollback-gate-'.uniqid();

    $quote = $this->postJson('/api/v1/checkout/quote', [
        'items' => [['product_id' => $product->id, 'package_id' => $package->id, 'quantity' => 1]],
    ], m31Headers($token))->assertOk();

    $fingerprint = (string) $quote->json('data.quote_fingerprint');
    $expectedTotal = (string) $quote->json('data.total.amount');

    $this->app->instance(MobileCheckoutCommitGate::class, new class implements MobileCheckoutCommitGate
    {
        public function afterAuthoritativePurchase($order, $attempt): void
        {
            throw new \RuntimeException('mobile_checkout_test_rollback_probe');
        }
    });

    $item = [
        'product_id' => (int) $product->id,
        'package_id' => (int) $package->id,
        'quantity' => 1,
        'requested_amount' => null,
        'requirements' => [],
    ];

    expect(fn () => app(ExecuteMobileCheckout::class)->handle($user, $item, $fingerprint, $key))
        ->toThrow(\RuntimeException::class, 'mobile_checkout_test_rollback_probe');

    expect(LedgerMoney::normalize((string) $wallet->fresh()->balance))->toBe($openingBalance)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(0)
        ->and(Order::query()->where('user_id', $user->id)->where('status', OrderStatus::Paid)->count())->toBe(0)
        ->and(Order::query()->where('user_id', $user->id)->count())->toBe(0)
        ->and(Fulfillment::query()->count())->toBe(0)
        ->and(MobileCheckoutAttempt::query()->where('user_id', $user->id)->where('status', MobileCheckoutAttemptStatus::Completed)->count())->toBe(0)
        ->and(MobileCheckoutAttempt::query()->where('user_id', $user->id)->whereNotNull('order_id')->count())->toBe(0);

    // Production no-op gate restored — same key/payload succeeds exactly once.
    $this->app->forgetInstance(MobileCheckoutCommitGate::class);

    $success = app(ExecuteMobileCheckout::class)->handle($user, $item, $fingerprint, $key);
    expect($success['status'])->toBe(200)
        ->and($success['data']['order']['total']['amount'])->toBe($expectedTotal);

    $retry = app(ExecuteMobileCheckout::class)->handle($user, $item, $fingerprint, $key);
    expect($retry['data']['replayed'])->toBeTrue()
        ->and($retry['data']['order']['order_number'])->toBe($success['data']['order']['order_number']);

    expect(Order::query()->where('user_id', $user->id)->where('status', OrderStatus::Paid)->count())->toBe(1)
        ->and(WalletTransaction::query()->where('type', WalletTransactionType::Purchase)->count())->toBe(1)
        ->and(Fulfillment::query()->count())->toBe(1)
        ->and(LedgerMoney::normalize((string) Wallet::query()->where('user_id', $user->id)->value('balance')))
        ->toBe(LedgerMoney::sub($openingBalance, $expectedTotal));
});
