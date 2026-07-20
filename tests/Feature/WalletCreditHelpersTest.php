<?php

declare(strict_types=1);

use App\Enums\CreditFacilityStatus;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('forUser defaults to disabled facility with null status', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    expect($wallet->credit_enabled)->toBeFalse()
        ->and($wallet->credit_status)->toBeNull()
        ->and(bcadd((string) $wallet->credit_limit, '0', 2))->toBe('0.00')
        ->and($wallet->payment_terms_days)->toBeNull()
        ->and($wallet->effectiveCreditLimit())->toBe('0.00');
});

test('wallet helpers compute spend debt and credit headroom', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '-80.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    expect($wallet->effectiveCreditLimit())->toBe('100.00')
        ->and($wallet->minimumAllowedBalance())->toBe('-100.00')
        ->and($wallet->availableToSpend())->toBe('20.00')
        ->and($wallet->availableCredit())->toBe('20.00')
        ->and($wallet->outstandingDebt())->toBe('80.00')
        ->and($wallet->isOverdrawn())->toBeTrue();
});

test('disabled credit treats limit as zero', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '25.00',
        'credit_enabled' => false,
        'credit_limit' => '500.00',
        'credit_status' => null,
    ]);

    expect($wallet->effectiveCreditLimit())->toBe('0.00')
        ->and($wallet->minimumAllowedBalance())->toBe('0.00')
        ->and($wallet->availableToSpend())->toBe('25.00')
        ->and($wallet->availableCredit())->toBe('25.00')
        ->and($wallet->outstandingDebt())->toBe('0.00')
        ->and($wallet->isOverdrawn())->toBeFalse();
});

test('null credit status treats limit as zero even when enabled', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'credit_status' => null,
    ]);

    expect($wallet->effectiveCreditLimit())->toBe('0.00')
        ->and($wallet->availableToSpend())->toBe('10.00');
});

test('platform wallets never expose a credit facility', function () {
    $platform = Wallet::forPlatform();
    $platform->update([
        'credit_enabled' => true,
        'credit_limit' => '999.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    expect($platform->type)->toBe(WalletType::Platform)
        ->and($platform->effectiveCreditLimit())->toBe('0.00')
        ->and($platform->minimumAllowedBalance())->toBe('0.00');
});

test('suspended credit facility treats limit as zero', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'credit_status' => CreditFacilityStatus::Suspended,
    ]);

    expect($wallet->effectiveCreditLimit())->toBe('0.00')
        ->and($wallet->availableToSpend())->toBe('10.00');
});
