<?php

declare(strict_types=1);

use App\Actions\Financial\GetCustomerTransactionDetail;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\FinancialDestinationType;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\CustomerTransactionDetailPresenter;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

function makeDetailTx(Wallet $wallet, array $overrides = []): WalletTransaction
{
    return WalletTransaction::query()->create(array_merge([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => 25,
        'status' => WalletTransaction::STATUS_POSTED,
        'public_ref' => WalletTransactionPublicRef::generate(),
        'posted_at' => now(),
        'meta' => [
            'balance_before' => '100.00',
            'balance_after' => '75.00',
            'previous_balance' => '100.00',
            'new_balance' => '75.00',
            'order_number' => 'ORD-TEST-1',
            'receipt' => [
                'version' => 1,
                'order_number' => 'ORD-TEST-1',
                'product_label' => 'Starter Pack',
                'currency' => 'USD',
            ],
        ],
    ], $overrides));
}

it('loads owned posted transaction detail', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $tx = makeDetailTx($wallet);

    $detail = app(GetCustomerTransactionDetail::class)->handle($user, (string) $tx->public_ref);

    expect($detail->publicReference)->toBe($tx->public_ref)
        ->and($detail->amount)->toBe('25.00')
        ->and($detail->balanceBefore)->toBe('100.00')
        ->and($detail->balanceAfter)->toBe('75.00')
        ->and($detail->relatedOrderNumber)->toBe('ORD-TEST-1')
        ->and($detail->productLabel)->toBe('Starter Pack')
        ->and($detail->moneyIn)->toBeFalse();
});

it('scopes realtime detail reads and defers reconciliation while printing', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $tx = makeDetailTx($wallet);
    $component = Livewire::actingAs($user)
        ->test('pages::frontend.wallet-transaction-detail', ['transaction' => $tx->public_ref]);

    $detailReads = 0;
    DB::listen(function ($query) use (&$detailReads): void {
        if (str_contains(strtolower($query->sql), 'wallet_transactions')) {
            $detailReads++;
        }
    });

    $component->dispatch(
        'customer-financial-invalidate',
        reasons: [CustomerFinancialInvalidationReason::TransactionPosted->value],
    );
    expect($detailReads)->toBe(0);

    $component
        ->call('markPrinting')
        ->dispatch(
            'customer-financial-invalidate',
            reasons: [CustomerFinancialInvalidationReason::RefundStateChanged->value],
        )
        ->assertSet('hasDeferredRefresh', true);
    expect($detailReads)->toBe(0);

    $component
        ->call('clearPrinting')
        ->assertSet('hasDeferredRefresh', false);
    expect($detailReads)->toBeGreaterThan(0);
});

it('denies pending rejected foreign platform and malformed references', function (): void {
    $user = User::factory()->create();
    $other = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $otherWallet = Wallet::forUser($other);

    $pending = WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 10,
        'status' => WalletTransaction::STATUS_PENDING,
        'public_ref' => 'WTX-AAAAAAAAAA',
        'posted_at' => null,
    ]);

    $rejected = WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 10,
        'status' => WalletTransaction::STATUS_REJECTED,
        'public_ref' => 'WTX-BBBBBBBBBB',
        'posted_at' => null,
    ]);

    $foreign = makeDetailTx($otherWallet, ['public_ref' => 'WTX-CCCCCCCCCC']);

    $platform = Wallet::forPlatform();
    $platformTx = makeDetailTx($platform, [
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'public_ref' => 'WTX-DDDDDDDDDD',
    ]);

    $this->actingAs($user)
        ->get(route('wallet.transactions.show', ['transaction' => $pending->public_ref]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('wallet.transactions.show', ['transaction' => $rejected->public_ref]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('wallet.transactions.show', ['transaction' => $foreign->public_ref]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('wallet.transactions.show', ['transaction' => $platformTx->public_ref]))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('wallet.transactions.show', ['transaction' => 'not-a-ref']))
        ->assertNotFound();

    $this->actingAs($user)
        ->get(route('wallet.transactions.show', ['transaction' => 'WTX-ZZZZZZZZZZ']))
        ->assertNotFound();
});

it('renders purchase facts print button and hides raw metadata', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $tx = makeDetailTx($wallet, [
        'meta' => [
            'balance_before' => '40.00',
            'balance_after' => '15.00',
            'order_number' => 'ORD-HIDE',
            'approved_by' => 99,
            'ip_address' => '10.0.0.1',
            'idempotency_secret' => 'secret-key',
            'receipt' => [
                'version' => 1,
                'order_number' => 'ORD-HIDE',
                'product_label' => 'Safe Pack',
                'currency' => 'USD',
            ],
        ],
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-transaction-detail', ['transaction' => $tx->public_ref])
        ->assertSee(__('messages.transaction_detail_title'))
        ->assertSee($tx->public_ref, false)
        ->assertSee('Safe Pack', false)
        ->assertSeeHtml('data-test="transaction-print-button"')
        ->assertSeeHtml('data-test="transaction-receipt"')
        ->assertSeeHtml('data-test="financial-centre-nav"')
        ->assertDontSee('10.0.0.1')
        ->assertDontSee('approved_by')
        ->assertDontSee('secret-key')
        ->assertDontSee('idempotency_secret');
});

it('falls back safely when balance snapshots and source are missing', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $tx = makeDetailTx($wallet, [
        'meta' => [],
        'reference_type' => null,
        'reference_id' => null,
    ]);

    $detail = app(GetCustomerTransactionDetail::class)->handle($user, (string) $tx->public_ref);
    $view = app(CustomerTransactionDetailPresenter::class)->present($detail, $user);

    expect($detail->hasBalanceSnapshots)->toBeFalse()
        ->and($view['balances_unavailable_label'])->toBe(__('messages.transaction_balances_unavailable'))
        ->and($view['source']['unavailable'])->toBeTrue()
        ->and(json_encode($view))->not->toContain('App\\Models');
});

it('links ledger rows and overview to transaction detail', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $tx = makeDetailTx($wallet, ['public_ref' => 'WTX-EEFF001122']);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet-transactions')
        ->assertSeeHtml('data-test="financial-ledger-detail-link"')
        ->assertSeeHtml(route('wallet.transactions.show', ['transaction' => 'WTX-EEFF001122'], false));

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSeeHtml(route('wallet.transactions.show', ['transaction' => 'WTX-EEFF001122'], false));
});

it('uses snapshot product label even when live order name changes', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $order = Order::create([
        'user_id' => $user->id,
        'order_number' => 'ORD-SNAP-1',
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
    ]);

    $tx = makeDetailTx($wallet, [
        'reference_type' => Order::class,
        'reference_id' => $order->id,
        'meta' => [
            'balance_before' => '20.00',
            'balance_after' => '10.00',
            'order_number' => 'ORD-SNAP-1',
            'receipt' => [
                'version' => 1,
                'order_number' => 'ORD-SNAP-1',
                'product_label' => 'Snapshot Name',
                'currency' => 'USD',
            ],
        ],
    ]);

    $detail = app(GetCustomerTransactionDetail::class)->handle($user, (string) $tx->public_ref);

    expect($detail->productLabel)->toBe('Snapshot Name')
        ->and($detail->sourceDestination?->type)->toBe(FinancialDestinationType::OrderDetail);
});

it('does not expose foreign source details when morph points elsewhere', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $wallet = Wallet::forUser($owner);
    $foreignOrder = Order::create([
        'user_id' => $intruder->id,
        'order_number' => 'ORD-FOREIGN',
        'currency' => 'USD',
        'subtotal' => 10,
        'fee' => 0,
        'total' => 10,
        'status' => OrderStatus::Paid,
    ]);

    $tx = makeDetailTx($wallet, [
        'reference_type' => Order::class,
        'reference_id' => $foreignOrder->id,
        'meta' => [
            'balance_before' => '50.00',
            'balance_after' => '25.00',
            'receipt' => [
                'version' => 1,
                'order_number' => 'ORD-FOREIGN',
                'product_label' => 'Should Hide',
            ],
        ],
    ]);

    $detail = app(GetCustomerTransactionDetail::class)->handle($owner, (string) $tx->public_ref);

    expect($detail->relatedOrderNumber)->toBeNull()
        ->and($detail->productLabel)->toBeNull()
        ->and($detail->isIntegrityAnomaly)->toBeTrue();
});

it('sets private cache headers on authenticated detail response', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $tx = makeDetailTx($wallet);

    $response = $this->actingAs($user)
        ->get(route('wallet.transactions.show', ['transaction' => $tx->public_ref]));

    $response->assertOk();
    $cacheControl = strtolower((string) $response->headers->get('Cache-Control'));
    expect($cacheControl === '' || str_contains($cacheControl, 'private') || str_contains($cacheControl, 'no-cache') || str_contains($cacheControl, 'no-store'))
        ->toBeTrue();
});

it('uses one detail lookup path without listing pending workflows', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $tx = makeDetailTx($wallet);

    DB::flushQueryLog();
    DB::enableQueryLog();

    app(GetCustomerTransactionDetail::class)->handle($user, (string) $tx->public_ref);

    $queries = collect(DB::getQueryLog())->pluck('query')->implode(' ');
    DB::disableQueryLog();

    expect(strtolower($queries))->not->toContain('activity_log')
        ->and(strtolower($queries))->not->toContain('notifications');
});
