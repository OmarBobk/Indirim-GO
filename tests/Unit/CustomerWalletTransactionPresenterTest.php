<?php

declare(strict_types=1);

use App\Actions\Financial\GetCustomerWalletTransactions;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\CustomerWalletTransactionPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('presents credit debit labels money ltr and no internal metadata', function (): void {
    $user = User::factory()->create(['locale' => 'en']);
    $wallet = Wallet::forUser($user);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 12.5,
        'status' => WalletTransaction::STATUS_POSTED,
        'public_ref' => 'WTX-CCDDEEFF00',
        'posted_at' => now(),
        'meta' => [
            'ledger_kernel' => 'm6.0.1',
            'approved_by' => 99,
            'ip_address' => '127.0.0.1',
            'reason' => 'customer note',
        ],
    ]);

    $page = app(GetCustomerWalletTransactions::class)->handle($user);
    $view = app(CustomerWalletTransactionPresenter::class)->presentPage($page, $user);
    $encoded = json_encode($view);

    expect($view['items'][0]['direction_label'])->toBe(__('messages.financial_direction_credited'))
        ->and($view['items'][0]['amount']['dir'])->toBe('ltr')
        ->and($view['items'][0]['amount']['formatted'])->toStartWith('+')
        ->and($view['items'][0]['public_reference'])->toBe('WTX-CCDDEEFF00')
        ->and($encoded)->not->toContain('ledger_kernel')
        ->and($encoded)->not->toContain('approved_by')
        ->and($encoded)->not->toContain('127.0.0.1')
        ->and($encoded)->not->toContain(WalletTransaction::class);

    app()->setLocale('ar');
    $ar = app(CustomerWalletTransactionPresenter::class)->presentPage(
        app(GetCustomerWalletTransactions::class)->handle($user),
        $user
    );

    expect($ar['items'][0]['type_label'])->toBe(__('messages.wallet_transaction_type_refund'));
});
