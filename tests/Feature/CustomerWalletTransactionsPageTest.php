<?php

declare(strict_types=1);

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('requires authentication and shows financial centre navigation', function (): void {
    $this->get(route('wallet.transactions.index'))->assertRedirect();

    $user = User::factory()->create();
    Wallet::forUser($user);

    $this->actingAs($user)
        ->get(route('wallet.transactions.index'))
        ->assertOk()
        ->assertSeeHtml('data-test="financial-centre-nav"')
        ->assertSeeHtml('data-test="financial-nav-transactions"')
        ->assertSeeHtml('aria-current="page"');
});

it('renders empty ledger with top-ups and refunds nav', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-transactions')
        ->assertSeeHtml('data-test="financial-ledger-empty"')
        ->assertSeeHtml('data-test="financial-nav-topups"')
        ->assertSeeHtml('data-test="financial-nav-refunds"');
});

it('synchronises filters in the url and resets page', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    foreach (range(1, 21) as $i) {
        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'type' => WalletTransactionType::Purchase,
            'direction' => WalletTransactionDirection::Debit,
            'amount' => $i,
            'status' => WalletTransaction::STATUS_POSTED,
            'public_ref' => 'WTX-'.str_pad((string) $i, 10, '0', STR_PAD_LEFT),
            'posted_at' => now()->subMinutes($i),
        ]);
    }

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-transactions')
        ->call('gotoPage', 2)
        ->assertSet('paginators.page', 2)
        ->call('setDirection', 'debit')
        ->assertSet('direction', 'debit')
        ->assertSet('paginators.page', 1)
        ->set('search', 'WTX-0000000021')
        ->assertSet('search', 'WTX-0000000021')
        ->assertSee('WTX-0000000021')
        ->call('clearFilters')
        ->assertSet('direction', 'all')
        ->assertSet('search', '');
});

it('overview links to the transactions workspace', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 12,
        'status' => WalletTransaction::STATUS_POSTED,
        'public_ref' => WalletTransactionPublicRef::generate(),
        'posted_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSeeHtml('data-test="financial-centre-nav"')
        ->assertSeeHtml('data-test="financial-view-transactions"')
        ->assertSee(route('wallet.transactions.index'));
});
