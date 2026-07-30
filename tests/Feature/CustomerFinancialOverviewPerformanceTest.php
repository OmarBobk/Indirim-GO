<?php

declare(strict_types=1);

use App\Actions\Financial\GetCustomerFinancialOverview;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('loads at most five posted transactions with a SQL limit', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    foreach (range(1, 12) as $i) {
        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::Purchase,
            'direction' => WalletTransactionDirection::Debit,
            'amount' => $i,
            'status' => WalletTransaction::STATUS_POSTED,
        ]);
    }

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $overview = app(GetCustomerFinancialOverview::class)->handle($user);

    expect($overview->recentTransactions)->toHaveCount(5);

    $txQueries = array_values(array_filter(
        $queries,
        fn (string $sql): bool => str_contains(strtolower($sql), 'wallet_transactions')
            && str_contains(strtolower($sql), 'limit')
    ));

    expect($txQueries)->not->toBeEmpty();

    $historyLoads = array_filter(
        $queries,
        fn (string $sql): bool => str_contains(strtolower($sql), 'wallet_transactions')
            && str_contains(strtolower($sql), 'limit 100')
    );

    expect($historyLoads)->toBeEmpty();
});

it('does not query activity notifications for overview', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user)->update(['balance' => '5.00']);

    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = strtolower($query->sql);
    });

    app(GetCustomerFinancialOverview::class)->handle($user);

    $notificationQueries = array_filter(
        $queries,
        fn (string $sql): bool => str_contains($sql, 'notifications')
    );

    expect($notificationQueries)->toBeEmpty();
});
