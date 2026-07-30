<?php

declare(strict_types=1);

use App\Actions\Financial\GetCustomerWalletTransactions;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('uses sql limit and does not load notifications or pending workflows', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    foreach (range(1, 30) as $i) {
        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::Purchase,
            'direction' => WalletTransactionDirection::Debit,
            'amount' => $i,
            'status' => WalletTransaction::STATUS_POSTED,
            'public_ref' => WalletTransactionPublicRef::generate(),
            'posted_at' => now()->subMinutes($i),
        ]);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    app(GetCustomerWalletTransactions::class)->handle($user);

    $history = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'wallet_transactions') && str_contains($sql, 'limit 100'));
    $notifications = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'notifications'));
    $topups = array_filter($queries, fn (string $sql): bool => str_contains($sql, 'topup_requests'));

    expect($history)->toBeEmpty()
        ->and($notifications)->toBeEmpty()
        ->and($topups)->toBeEmpty();
});

it('has the ledger composite index and unique public_ref', function (): void {
    expect(Schema::hasColumns('wallet_transactions', ['public_ref', 'posted_at']))->toBeTrue();

    $indexes = Schema::getIndexes('wallet_transactions');
    $names = collect($indexes)->pluck('name')->all();

    expect($names)->toContain('wallet_transactions_wallet_status_posted_idx')
        ->and($names)->toContain('wallet_transactions_public_ref_unique');
});
