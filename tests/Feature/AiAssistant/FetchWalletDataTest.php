<?php

declare(strict_types=1);

use App\Actions\AiAssistant\FetchWalletData;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;

it('returns wallet data when looking up by username', function (): void {
    $user = User::factory()->create(['username' => 'zain']);
    $wallet = Wallet::create([
        'user_id' => $user->id,
        'type' => WalletType::Customer,
        'balance' => 150,
        'currency' => 'USD',
    ]);

    WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 175,
        'status' => WalletTransaction::STATUS_POSTED,
        'idempotency_key' => 'test-topup-1',
    ]);

    $data = app(FetchWalletData::class)->handle('zain');

    expect($data)->not->toBeNull();
    expect($data['user']['username'])->toBe('zain');
    expect($data['wallet']['balance'])->toBe('150.00');
    expect($data['recent_transactions'])->toHaveCount(1);
});

it('returns wallet data when looking up by numeric user id string', function (): void {
    $user = User::factory()->create(['username' => 'zain']);
    Wallet::create([
        'user_id' => $user->id,
        'type' => WalletType::Customer,
        'balance' => 50,
        'currency' => 'USD',
    ]);

    $data = app(FetchWalletData::class)->handle((string) $user->id);

    expect($data)->not->toBeNull();
    expect($data['user']['id'])->toBe($user->id);
    expect($data['wallet']['balance'])->toBe('50.00');
});

it('returns null when user not found', function (): void {
    expect(app(FetchWalletData::class)->handle('nobody'))->toBeNull();
});

it('returns user data with null wallet when wallet row is missing', function (): void {
    $user = User::factory()->create(['username' => 'nowallet']);

    $data = app(FetchWalletData::class)->handle('nowallet');

    expect($data)->not->toBeNull();
    expect($data['wallet'])->toBeNull();
    expect($data['recent_transactions'])->toBe([]);
});
