<?php

declare(strict_types=1);

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Exceptions\PostedWalletTransactionImmutableException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('posted wallet transaction amount direction type wallet and idempotency are immutable', function () {
    $wallet = Wallet::forUser(User::factory()->create());
    $other = Wallet::forUser(User::factory()->create());

    $tx = WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Adjustment,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '10.00',
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => 'immutable:1',
    ]);

    expect(fn () => $tx->update(['amount' => '11.00']))
        ->toThrow(PostedWalletTransactionImmutableException::class);

    expect(fn () => $tx->fresh()->update(['direction' => WalletTransactionDirection::Debit]))
        ->toThrow(PostedWalletTransactionImmutableException::class);

    expect(fn () => $tx->fresh()->update(['type' => WalletTransactionType::Purchase]))
        ->toThrow(PostedWalletTransactionImmutableException::class);

    expect(fn () => $tx->fresh()->update(['wallet_id' => $other->id]))
        ->toThrow(PostedWalletTransactionImmutableException::class);

    expect(fn () => $tx->fresh()->update(['idempotency_key' => 'immutable:2']))
        ->toThrow(PostedWalletTransactionImmutableException::class);

    expect(fn () => $tx->fresh()->delete())
        ->toThrow(PostedWalletTransactionImmutableException::class);
});

test('posted wallet transaction meta may still be appended', function () {
    $wallet = Wallet::forUser(User::factory()->create());
    $tx = WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Adjustment,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '1.00',
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => 'immutable:meta',
        'meta' => ['a' => 1],
    ]);

    $tx->update(['meta' => ['a' => 1, 'b' => 2]]);

    expect(data_get($tx->fresh()->meta, 'b'))->toBe(2);
});

test('pending wallet transaction may transition to rejected', function () {
    $wallet = Wallet::forUser(User::factory()->create());
    $tx = WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '1.00',
        'status' => WalletTransaction::STATUS_PENDING,
    ]);

    $tx->update(['status' => WalletTransaction::STATUS_REJECTED]);

    expect($tx->fresh()->status)->toBe(WalletTransaction::STATUS_REJECTED);
});
