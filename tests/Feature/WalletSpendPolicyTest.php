<?php

declare(strict_types=1);

use App\Enums\CreditFacilityStatus;
use App\Enums\WalletSpendFailureReason;
use App\Enums\WalletType;
use App\Exceptions\WalletSpendDeniedException;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletSpendPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('evaluate allows debit within prepaid balance when credit is off', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '50.00',
        'credit_enabled' => false,
        'credit_limit' => '100.00',
    ]);

    $decision = app(WalletSpendPolicy::class)->evaluate($wallet, '40.00');

    expect($decision->allowed)->toBeTrue()
        ->and($decision->availableToSpend)->toBe('50.00')
        ->and($decision->remainingCredit)->toBe('50.00')
        ->and($decision->effectiveCreditLimit)->toBe('0.00')
        ->and($decision->failureReason)->toBeNull();
});

test('evaluate allows overdraft within credit limit', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $decision = app(WalletSpendPolicy::class)->evaluate($wallet, '20.00');

    expect($decision->allowed)->toBeTrue()
        ->and($decision->availableToSpend)->toBe('110.00')
        ->and($decision->remainingCredit)->toBe('110.00')
        ->and($decision->effectiveCreditLimit)->toBe('100.00')
        ->and($decision->failureReason)->toBeNull();
});

test('evaluate denies debit beyond credit facility', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '-80.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $decision = app(WalletSpendPolicy::class)->evaluate($wallet, '25.00');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->isDenied())->toBeTrue()
        ->and($decision->availableToSpend)->toBe('20.00')
        ->and($decision->remainingCredit)->toBe('20.00')
        ->and($decision->effectiveCreditLimit)->toBe('100.00')
        ->and($decision->failureReason)->toBe(WalletSpendFailureReason::InsufficientFunds)
        ->and($decision->failureReason?->value)->toBe('insufficient_funds');
});

test('evaluate denies debit when credit is disabled and balance is short', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.00',
        'credit_enabled' => false,
        'credit_limit' => '500.00',
    ]);

    $decision = app(WalletSpendPolicy::class)->evaluate($wallet, '20.00');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->availableToSpend)->toBe('10.00')
        ->and($decision->effectiveCreditLimit)->toBe('0.00')
        ->and($decision->failureReason)->toBe(WalletSpendFailureReason::InsufficientFunds);
});

test('evaluate returns invalid_amount for non-positive debit', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => '50.00']);

    $decision = app(WalletSpendPolicy::class)->evaluate($wallet, '0.00');

    expect($decision->allowed)->toBeFalse()
        ->and($decision->failureReason)->toBe(WalletSpendFailureReason::InvalidAmount)
        ->and($decision->failureReason?->value)->toBe('invalid_amount');
});

test('assertCanDebit returns decision when allowed and throws denied exception with reason', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '5.00',
        'credit_enabled' => true,
        'credit_limit' => '10.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $policy = app(WalletSpendPolicy::class);

    $allowed = $policy->assertCanDebit($wallet, '12.00');
    expect($allowed->allowed)->toBeTrue();

    try {
        $policy->assertCanDebit($wallet, '20.00');
        expect(false)->toBeTrue();
    } catch (WalletSpendDeniedException $exception) {
        expect($exception->reason())->toBe(WalletSpendFailureReason::InsufficientFunds)
            ->and($exception->decision->availableToSpend)->toBe('15.00')
            ->and($exception->getMessage())->toBe(__('messages.wallet_spend_insufficient', [
                'available' => '15.00',
                'currency' => config('billing.currency', 'USD'),
            ]));
    }
});

test('platform wallets never allow spending beyond cash balance', function () {
    $platform = Wallet::forPlatform();
    $platform->update([
        'balance' => '40.00',
        'credit_enabled' => true,
        'credit_limit' => '999.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $decision = app(WalletSpendPolicy::class)->evaluate($platform, '41.00');

    expect($platform->type)->toBe(WalletType::Platform)
        ->and($decision->allowed)->toBeFalse()
        ->and($decision->effectiveCreditLimit)->toBe('0.00')
        ->and($decision->failureReason)->toBe(WalletSpendFailureReason::InsufficientFunds);
});
