<?php

declare(strict_types=1);

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('refreshes page one on financial invalidate', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 10,
        'status' => WalletTransaction::STATUS_POSTED,
        'public_ref' => 'WTX-AAAAAAAAAA',
        'posted_at' => now()->subMinute(),
    ]);

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.wallet-transactions')
        ->assertSee('WTX-AAAAAAAAAA');

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 20,
        'status' => WalletTransaction::STATUS_POSTED,
        'public_ref' => 'WTX-BBBBBBBBBB',
        'posted_at' => now(),
    ]);

    $component
        ->dispatch('customer-financial-invalidate')
        ->assertSee('WTX-BBBBBBBBBB')
        ->assertSet('hasPendingRefresh', false);
});

it('shows pending banner on page two without replacing rows', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    foreach (range(1, 25) as $i) {
        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::Purchase,
            'direction' => WalletTransactionDirection::Debit,
            'amount' => $i,
            'status' => WalletTransaction::STATUS_POSTED,
            'public_ref' => 'WTX-'.str_pad(dechex($i), 10, '0', STR_PAD_LEFT),
            'posted_at' => now()->subMinutes(30 - $i),
        ]);
    }

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.wallet-transactions')
        ->call('gotoPage', 2)
        ->assertSet('hasPendingRefresh', false);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 99,
        'status' => WalletTransaction::STATUS_POSTED,
        'public_ref' => 'WTX-NEWNEWNEW1',
        'posted_at' => now()->addMinute(),
    ]);

    $component
        ->dispatch('customer-financial-invalidate')
        ->assertSet('hasPendingRefresh', true)
        ->assertDontSee('WTX-NEWNEWNEW1');

    $component
        ->call('applyPendingRefresh')
        ->assertSet('hasPendingRefresh', false)
        ->assertSet('paginators.page', 1)
        ->assertSee('WTX-NEWNEWNEW1');
});

it('does not listen for activity invalidation', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user);

    $instance = Livewire::actingAs($user)
        ->test('pages::frontend.wallet-transactions')
        ->instance();

    $onEvents = [];
    foreach ((new ReflectionClass($instance))->getMethods() as $method) {
        foreach ($method->getAttributes(\Livewire\Attributes\On::class) as $attribute) {
            $onEvents[] = $attribute->getArguments()[0] ?? null;
        }
    }

    expect($onEvents)->toContain('customer-financial-invalidate')
        ->and($onEvents)->not->toContain('customer-activity-invalidate');
});
