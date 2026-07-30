<?php

declare(strict_types=1);

use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Events\CustomerFinancialStateChanged;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

test('wallet reconcile audit reports drift without mutating by default', function () {
    Event::fake([CustomerFinancialStateChanged::class]);
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => 5]);

    WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 20,
        'status' => WalletTransaction::STATUS_POSTED,
    ]);

    WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => 3,
        'status' => WalletTransaction::STATUS_POSTED,
    ]);

    Artisan::call('wallet:reconcile', [
        '--user' => $user->id,
    ]);

    $output = Artisan::output();

    expect($output)->toContain(sprintf('Wallet %d (user %d):', $wallet->id, $user->id))
        ->and($output)->toContain('[audit-only]');
    $wallet->refresh();
    expect(LedgerMoney::normalize((string) $wallet->balance))->toBe('5.00');
    Event::assertNotDispatched(CustomerFinancialStateChanged::class);
});

test('wallet reconcile dry-run does not mutate', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => 5]);

    WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 20,
        'status' => WalletTransaction::STATUS_POSTED,
    ]);

    Artisan::call('wallet:reconcile', [
        '--user' => $user->id,
        '--dry-run' => true,
    ]);

    $wallet->refresh();
    expect(LedgerMoney::normalize((string) $wallet->balance))->toBe('5.00');
});

test('wallet reconcile repair snapshots balance to ledger sum and is idempotent', function () {
    Event::fake([CustomerFinancialStateChanged::class]);
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => 0]);

    WalletTransaction::create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 12,
        'status' => WalletTransaction::STATUS_POSTED,
    ]);

    Artisan::call('wallet:reconcile', [
        '--user' => $user->id,
        '--repair' => true,
    ]);

    $wallet->refresh();
    expect(LedgerMoney::normalize((string) $wallet->balance))->toBe('12.00');
    expect(Artisan::output())->toContain('Repaired 1 wallet');
    Event::assertDispatched(
        CustomerFinancialStateChanged::class,
        fn (CustomerFinancialStateChanged $event): bool => $event->userId === $user->id
            && $event->reasons === [CustomerFinancialInvalidationReason::BalanceChanged],
    );

    $txCount = WalletTransaction::query()->where('wallet_id', $wallet->id)->count();

    Artisan::call('wallet:reconcile', [
        '--user' => $user->id,
        '--repair' => true,
    ]);

    expect(Artisan::output())->toContain('No drift detected');
    expect(WalletTransaction::query()->where('wallet_id', $wallet->id)->count())->toBe($txCount);
    Event::assertDispatchedTimes(CustomerFinancialStateChanged::class, 1);
});
