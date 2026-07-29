<?php

declare(strict_types=1);

use App\DTOs\WalletPosting;
use App\Enums\CreditFacilityStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\InvalidPendingPromotionException;
use App\Exceptions\InvalidWalletPostingAmountException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\WalletLedger;
use App\Support\LedgerMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function kernelWallet(string $balance = '100.00'): Wallet
{
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => $balance]);

    return $wallet->fresh();
}

test('credit creates one posted transaction and changes balance once', function () {
    $wallet = kernelWallet('10.00');
    $ledger = app(WalletLedger::class);

    $result = $ledger->postCredit(
        wallet: $wallet,
        type: WalletTransactionType::Adjustment,
        amount: '5.50',
        idempotencyKey: 'test:credit:'.Str::uuid(),
    );

    expect($result->wasReplayed)->toBeFalse()
        ->and($result->previousBalance)->toBe('10.00')
        ->and($result->newBalance)->toBe('15.50')
        ->and(LedgerMoney::normalize((string) $result->wallet->balance))->toBe('15.50')
        ->and($result->transaction->status)->toBe(WalletTransaction::STATUS_POSTED)
        ->and(data_get($result->transaction->meta, 'balance_before'))->toBe('10.00')
        ->and(data_get($result->transaction->meta, 'balance_after'))->toBe('15.50')
        ->and(data_get($result->transaction->meta, 'ledger_kernel'))->toBe(WalletLedger::KERNEL_VERSION);

    expect(WalletTransaction::query()->where('wallet_id', $wallet->id)->count())->toBe(1);
});

test('debit creates one posted transaction and changes balance once', function () {
    $wallet = kernelWallet('20.00');
    $ledger = app(WalletLedger::class);

    $result = $ledger->postDebit(
        wallet: $wallet,
        type: WalletTransactionType::Purchase,
        amount: '7.25',
        idempotencyKey: 'test:debit:'.Str::uuid(),
    );

    expect($result->previousBalance)->toBe('20.00')
        ->and($result->newBalance)->toBe('12.75')
        ->and(LedgerMoney::normalize((string) $wallet->fresh()->balance))->toBe('12.75');
});

test('zero negative malformed scientific and overflow amounts are rejected', function (string $amount) {
    $wallet = kernelWallet();
    $ledger = app(WalletLedger::class);

    expect(fn () => $ledger->postCredit(
        wallet: $wallet,
        type: WalletTransactionType::Adjustment,
        amount: $amount,
        idempotencyKey: 'test:bad:'.Str::uuid(),
    ))->toThrow(InvalidWalletPostingAmountException::class);
})->with([
    'zero' => '0',
    'negative' => '-1.00',
    'empty' => ' ',
    'scientific' => '1e2',
    'overflow' => '100000000.00',
    'letters' => 'abc',
]);

test('duplicate matching idempotency key returns original without second balance change', function () {
    $wallet = kernelWallet('10.00');
    $ledger = app(WalletLedger::class);
    $key = 'test:replay:'.Str::uuid();

    $first = $ledger->postCredit(
        wallet: $wallet,
        type: WalletTransactionType::Adjustment,
        amount: '2.00',
        idempotencyKey: $key,
    );

    $second = $ledger->postCredit(
        wallet: $wallet->fresh(),
        type: WalletTransactionType::Adjustment,
        amount: '2.00',
        idempotencyKey: $key,
    );

    expect($second->wasReplayed)->toBeTrue()
        ->and($second->transaction->id)->toBe($first->transaction->id)
        ->and(LedgerMoney::normalize((string) $wallet->fresh()->balance))->toBe('12.00')
        ->and(WalletTransaction::query()->where('wallet_id', $wallet->id)->count())->toBe(1);
});

test('duplicate conflicting idempotency key throws integrity exception', function () {
    $wallet = kernelWallet('10.00');
    $ledger = app(WalletLedger::class);
    $key = 'test:conflict:'.Str::uuid();

    $ledger->postCredit(
        wallet: $wallet,
        type: WalletTransactionType::Adjustment,
        amount: '2.00',
        idempotencyKey: $key,
    );

    expect(fn () => $ledger->postCredit(
        wallet: $wallet->fresh(),
        type: WalletTransactionType::Adjustment,
        amount: '3.00',
        idempotencyKey: $key,
    ))->toThrow(IdempotencyConflictException::class);
});

test('rollback leaves neither balance nor ledger partially changed', function () {
    $wallet = kernelWallet('50.00');
    $ledger = app(WalletLedger::class);

    try {
        DB::transaction(function () use ($ledger, $wallet): void {
            $ledger->postCredit(
                wallet: $wallet,
                type: WalletTransactionType::Adjustment,
                amount: '5.00',
                idempotencyKey: 'test:rollback:'.Str::uuid(),
            );

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(LedgerMoney::normalize((string) $wallet->fresh()->balance))->toBe('50.00')
        ->and(WalletTransaction::query()->where('wallet_id', $wallet->id)->count())->toBe(0);
});

test('active credit facility allows debit below zero within floor', function () {
    $wallet = kernelWallet('10.00');
    $wallet->update([
        'credit_enabled' => true,
        'credit_limit' => '50.00',
        'credit_status' => CreditFacilityStatus::Active,
        'payment_terms_days' => 30,
    ]);

    $result = app(WalletLedger::class)->postDebit(
        wallet: $wallet->fresh(),
        type: WalletTransactionType::Purchase,
        amount: '40.00',
        idempotencyKey: 'test:credit-ok:'.Str::uuid(),
        minimumAllowedBalance: $wallet->fresh()->minimumAllowedBalance(),
    );

    expect($result->newBalance)->toBe('-30.00');
});

test('debit beyond credit facility floor is rejected', function () {
    $wallet = kernelWallet('10.00');
    $wallet->update([
        'credit_enabled' => true,
        'credit_limit' => '20.00',
        'credit_status' => CreditFacilityStatus::Active,
        'payment_terms_days' => 30,
    ]);

    expect(fn () => app(WalletLedger::class)->postDebit(
        wallet: $wallet->fresh(),
        type: WalletTransactionType::Purchase,
        amount: '40.00',
        idempotencyKey: 'test:credit-deny:'.Str::uuid(),
        minimumAllowedBalance: $wallet->fresh()->minimumAllowedBalance(),
    ))->toThrow(InsufficientWalletBalanceException::class);
});

test('suspended credit facility rejects overdraft', function () {
    $wallet = kernelWallet('5.00');
    $wallet->update([
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'credit_status' => CreditFacilityStatus::Suspended,
        'payment_terms_days' => 30,
    ]);

    expect(fn () => app(WalletLedger::class)->postDebit(
        wallet: $wallet->fresh(),
        type: WalletTransactionType::Purchase,
        amount: '10.00',
        idempotencyKey: 'test:suspended:'.Str::uuid(),
        minimumAllowedBalance: $wallet->fresh()->minimumAllowedBalance(),
    ))->toThrow(InsufficientWalletBalanceException::class);
});

test('credit while wallet is negative works', function () {
    $wallet = kernelWallet('-25.00');

    $result = app(WalletLedger::class)->postCredit(
        wallet: $wallet,
        type: WalletTransactionType::Topup,
        amount: '10.00',
        idempotencyKey: 'test:debt-credit:'.Str::uuid(),
    );

    expect($result->newBalance)->toBe('-15.00');
});

test('pending promotion posts once and preserves amount', function () {
    $wallet = kernelWallet('0.00');
    $pending = WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '15.00',
        'status' => WalletTransaction::STATUS_PENDING,
        'reference_type' => User::class,
        'reference_id' => $wallet->user_id,
        'meta' => ['note' => 'keep-me'],
    ]);

    $result = app(WalletLedger::class)->post(new WalletPosting(
        wallet: $wallet,
        type: WalletTransactionType::Topup,
        direction: WalletTransactionDirection::Credit,
        amount: '15.00',
        idempotencyKey: 'topup:promo-test',
        meta: ['approved_by' => 1],
        referenceType: User::class,
        referenceId: (int) $wallet->user_id,
        pendingTransaction: $pending,
    ));

    expect($result->wasPromoted)->toBeTrue()
        ->and($result->transaction->id)->toBe($pending->id)
        ->and($result->transaction->status)->toBe(WalletTransaction::STATUS_POSTED)
        ->and(data_get($result->transaction->meta, 'note'))->toBe('keep-me')
        ->and(LedgerMoney::normalize((string) $wallet->fresh()->balance))->toBe('15.00');
});

test('pending promotion rejects amount mismatch', function () {
    $wallet = kernelWallet('0.00');
    $pending = WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => '10.00',
        'status' => WalletTransaction::STATUS_PENDING,
    ]);

    expect(fn () => app(WalletLedger::class)->post(new WalletPosting(
        wallet: $wallet,
        type: WalletTransactionType::Refund,
        direction: WalletTransactionDirection::Credit,
        amount: '11.00',
        idempotencyKey: 'refund:mismatch',
        pendingTransaction: $pending,
    )))->toThrow(InvalidPendingPromotionException::class);
});
