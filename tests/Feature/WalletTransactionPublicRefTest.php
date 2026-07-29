<?php

declare(strict_types=1);

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Services\WalletLedger;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates valid unique public references', function (): void {
    $a = WalletTransactionPublicRef::generate();
    $b = WalletTransactionPublicRef::generate();

    expect(WalletTransactionPublicRef::isValidFormat($a))->toBeTrue()
        ->and(WalletTransactionPublicRef::isValidFormat($b))->toBeTrue()
        ->and($a)->not->toBe($b);
});

it('assigns public_ref and posted_at through WalletLedger', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    $result = app(WalletLedger::class)->postCredit(
        wallet: $wallet,
        type: WalletTransactionType::Adjustment,
        amount: '15.00',
        idempotencyKey: 'test:public-ref:'.uniqid(),
        meta: ['reason' => 'fixture'],
    );

    $tx = $result->transaction->fresh();

    expect($tx->public_ref)->not->toBeNull()
        ->and(WalletTransactionPublicRef::isValidFormat((string) $tx->public_ref))->toBeTrue()
        ->and($tx->posted_at)->not->toBeNull()
        ->and($tx->direction)->toBe(WalletTransactionDirection::Credit);
});
