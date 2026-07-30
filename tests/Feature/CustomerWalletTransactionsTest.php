<?php

declare(strict_types=1);

use App\Actions\Financial\GetCustomerWalletTransactions;
use App\DTOs\Financial\WalletTransactionFilters;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function makePostedLedgerTx(Wallet $wallet, array $overrides = []): WalletTransaction
{
    return WalletTransaction::query()->create(array_merge([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => 10,
        'status' => WalletTransaction::STATUS_POSTED,
        'public_ref' => WalletTransactionPublicRef::generate(),
        'posted_at' => now(),
        'meta' => [],
    ], $overrides));
}

it('returns only posted customer ledger types and excludes pending rejected settlement', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    makePostedLedgerTx($wallet, [
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 50,
        'posted_at' => now()->subMinute(),
    ]);

    makePostedLedgerTx($wallet, [
        'type' => WalletTransactionType::Settlement,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 5,
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 20,
        'status' => WalletTransaction::STATUS_PENDING,
        'posted_at' => null,
        'public_ref' => null,
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 8,
        'status' => WalletTransaction::STATUS_REJECTED,
        'posted_at' => null,
        'public_ref' => null,
    ]);

    $page = app(GetCustomerWalletTransactions::class)->handle($user);

    expect($page->total)->toBe(1)
        ->and($page->items[0]->transactionType)->toBe(WalletTransactionType::Topup);
});

it('filters by direction and type', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    makePostedLedgerTx($wallet, [
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 40,
        'posted_at' => now()->subMinutes(2),
    ]);
    makePostedLedgerTx($wallet, [
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => 15,
        'posted_at' => now()->subMinute(),
    ]);

    $credits = app(GetCustomerWalletTransactions::class)->handle(
        $user,
        WalletTransactionFilters::fromInput(['direction' => 'credit'])
    );
    $purchases = app(GetCustomerWalletTransactions::class)->handle(
        $user,
        WalletTransactionFilters::fromInput(['type' => 'purchase'])
    );

    expect($credits->total)->toBe(1)
        ->and($credits->items[0]->isCredit)->toBeTrue()
        ->and($purchases->total)->toBe(1)
        ->and($purchases->items[0]->transactionType)->toBe(WalletTransactionType::Purchase);
});

it('searches by public reference and owned order number only', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $ownerWallet = Wallet::forUser($owner);
    $otherWallet = Wallet::forUser($other);

    $ownerOrder = Order::query()->create([
        'user_id' => $owner->id,
        'order_number' => 'OWN-SEARCH-1',
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => \App\Enums\OrderStatus::Paid,
    ]);

    $otherOrder = Order::query()->create([
        'user_id' => $other->id,
        'order_number' => 'OTH-SECRET-9',
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => \App\Enums\OrderStatus::Paid,
    ]);

    $ref = 'WTX-AABBCCDDEE';
    makePostedLedgerTx($ownerWallet, [
        'public_ref' => $ref,
        'type' => WalletTransactionType::Purchase,
        'reference_type' => Order::class,
        'reference_id' => $ownerOrder->id,
        'meta' => ['order_number' => $ownerOrder->order_number],
        'posted_at' => now()->subMinute(),
    ]);

    makePostedLedgerTx($otherWallet, [
        'public_ref' => 'WTX-FFEEDDCCBB',
        'type' => WalletTransactionType::Purchase,
        'reference_type' => Order::class,
        'reference_id' => $otherOrder->id,
        'meta' => ['order_number' => $otherOrder->order_number],
    ]);

    $byRef = app(GetCustomerWalletTransactions::class)->handle(
        $owner,
        WalletTransactionFilters::fromInput(['search' => 'WTX-AABB'])
    );
    $byOrder = app(GetCustomerWalletTransactions::class)->handle(
        $owner,
        WalletTransactionFilters::fromInput(['search' => 'OWN-SEARCH'])
    );
    $foreign = app(GetCustomerWalletTransactions::class)->handle(
        $owner,
        WalletTransactionFilters::fromInput(['search' => 'OTH-SECRET'])
    );

    expect($byRef->total)->toBe(1)
        ->and($byOrder->total)->toBe(1)
        ->and($foreign->total)->toBe(0);
});

it('orders by posted_at then id and paginates twenty', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    foreach (range(1, 25) as $i) {
        makePostedLedgerTx($wallet, [
            'amount' => $i,
            'posted_at' => now()->subMinutes(25 - $i),
            'public_ref' => 'WTX-'.str_pad(dechex($i), 10, '0', STR_PAD_LEFT),
        ]);
    }

    $page1 = app(GetCustomerWalletTransactions::class)->handle(
        $user,
        WalletTransactionFilters::fromInput(['page' => 1])
    );
    $page2 = app(GetCustomerWalletTransactions::class)->handle(
        $user,
        WalletTransactionFilters::fromInput(['page' => 2])
    );

    expect($page1->items)->toHaveCount(20)
        ->and($page2->items)->toHaveCount(5)
        ->and($page1->items[0]->amount)->toBe('25.00')
        ->and($page1->total)->toBe(25);
});

it('normalizes invalid filters safely', function (): void {
    $filters = WalletTransactionFilters::fromInput([
        'direction' => 'hack',
        'type' => 'drop-table',
        'search' => str_repeat('a', 100),
        'date_from' => 'not-a-date',
        'date_to' => '2026-01-01',
    ]);

    expect($filters->direction)->toBe('all')
        ->and($filters->type)->toBe('all')
        ->and(mb_strlen($filters->search))->toBe(64)
        ->and($filters->dateFrom)->toBeNull()
        ->and($filters->dateTo)->toBe('2026-01-01');
});

it('excludes another users transactions completely', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    makePostedLedgerTx(Wallet::forUser($other), ['amount' => 999]);

    $page = app(GetCustomerWalletTransactions::class)->handle($owner);

    expect($page->total)->toBe(0);
});

it('excludes platform wallet rows from customer ledger path', function (): void {
    $user = User::factory()->create();
    $customerWallet = Wallet::forUser($user);

    makePostedLedgerTx($customerWallet, [
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 30,
    ]);

    // Platform settlements must never appear via customer wallet scoping.
    $page = app(GetCustomerWalletTransactions::class)->handle($user);

    expect($page->total)->toBe(1)
        ->and($page->items[0]->amount)->toBe('30.00')
        ->and(collect($page->items)->pluck('transactionType'))->not->toContain(WalletTransactionType::Settlement);
});

it('orders by posting time not request creation for promoted style rows', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    $olderRequestNewerPost = makePostedLedgerTx($wallet, [
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 70,
        'created_at' => now()->subDays(3),
        'posted_at' => now()->subHour(),
        'public_ref' => 'WTX-1111111111',
    ]);

    $newerRequestOlderPost = makePostedLedgerTx($wallet, [
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => 20,
        'created_at' => now()->subHour(),
        'posted_at' => now()->subDays(2),
        'public_ref' => 'WTX-2222222222',
    ]);

    // Force created_at independently of Eloquent timestamps.
    WalletTransaction::query()->whereKey($olderRequestNewerPost->id)->update(['created_at' => now()->subDays(3)]);
    WalletTransaction::query()->whereKey($newerRequestOlderPost->id)->update(['created_at' => now()->subHour()]);

    $page = app(GetCustomerWalletTransactions::class)->handle($user);

    expect($page->items[0]->publicReference)->toBe('WTX-1111111111')
        ->and($page->items[1]->publicReference)->toBe('WTX-2222222222');
});
