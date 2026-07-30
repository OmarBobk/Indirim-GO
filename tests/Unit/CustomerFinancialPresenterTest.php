<?php

declare(strict_types=1);

use App\Actions\Financial\GetCustomerFinancialOverview;
use App\Enums\CreditFacilityStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\CustomerFinancialPresenter;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

it('presents english and arabic financial labels', function (): void {
    $user = User::factory()->create(['locale' => 'en']);
    Wallet::forUser($user)->update([
        'balance' => '-25.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => Wallet::forUser($user)->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 5,
        'status' => WalletTransaction::STATUS_POSTED,
    ]);

    app()->setLocale('en');
    $en = app(CustomerFinancialPresenter::class)->present(
        app(GetCustomerFinancialOverview::class)->handle($user),
        $user
    );

    expect($en['balance']['labels']['available'])->toBe(__('messages.wallet_available_to_spend'))
        ->and($en['balance']['labels']['debt'])->toBe(__('messages.wallet_you_owe'))
        ->and($en['recent']['items'][0]['status_label'])->toBe(__('messages.financial_status_refunded'))
        ->and($en['balance']['available_to_spend']['dir'])->toBe('ltr');

    app()->setLocale('ar');
    $ar = app(CustomerFinancialPresenter::class)->present(
        app(GetCustomerFinancialOverview::class)->handle($user),
        $user
    );

    expect($ar['balance']['labels']['available'])->toBe(__('messages.wallet_available_to_spend'))
        ->and($ar['pending']['items'][0]['actor_label'] ?? __('messages.financial_status_needs_action'))
        ->toBe(__('messages.financial_status_needs_action'));
});
