<?php

declare(strict_types=1);

use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\PaymentMethod;
use App\Models\TopupRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\FrontendMoney;
use App\Support\PurchaseResumeIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

it('renders available balance add funds and hides empty pending', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user)->update(['balance' => '80.00']);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSeeHtml('data-test="financial-overview"')
        ->assertSeeHtml('data-test="wallet-available-to-spend"')
        ->assertSeeHtml('data-test="wallet-add-funds"')
        ->assertDontSeeHtml('data-test="financial-pending-summary"')
        ->assertSeeHtml('data-test="financial-loyalty-link"')
        ->assertDontSeeHtml('data-test="loyalty-tier-card"');
});

it('shows continue purchase only when resume intent is valid', function (): void {
    $user = User::factory()->create();
    Wallet::forUser($user);

    PurchaseResumeIntent::store(['source' => PurchaseResumeIntent::SOURCE_CART]);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSeeHtml('data-test="purchase-resume-banner"')
        ->assertSeeHtml('data-test="purchase-resume-continue"');
});

it('shows pending summary and recent posted rows only', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);

    TopupRequest::query()->create([
        'user_id' => $user->id,
        'wallet_id' => $wallet->id,
        'amount' => 25,
        'currency' => 'USD',
        'status' => TopupRequestStatus::Pending,
        'payment_method_id' => PaymentMethod::query()->where('name', 'Sham Cash')->value('id'),
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 40,
        'status' => WalletTransaction::STATUS_POSTED,
    ]);

    WalletTransaction::query()->create([
        'wallet_id' => $wallet->id,
        'type' => WalletTransactionType::Topup,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 10,
        'status' => WalletTransaction::STATUS_PENDING,
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSeeHtml('data-test="financial-pending-summary"')
        ->assertSee(__('messages.financial_status_waiting_review'))
        ->assertSeeHtml('data-test="financial-transaction-row"')
        ->assertDontSeeHtml('data-pending-kind="topup_pending" data-test="financial-transaction-row"');
});

it('does not show another users transactions on the page', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Wallet::forUser($owner)->update(['balance' => '5.00']);
    $otherWallet = Wallet::forUser($other);

    WalletTransaction::query()->create([
        'wallet_id' => $otherWallet->id,
        'type' => WalletTransactionType::Purchase,
        'direction' => WalletTransactionDirection::Debit,
        'amount' => 777,
        'status' => WalletTransaction::STATUS_POSTED,
        'meta' => ['note' => 'SECRET-OTHER-USER'],
    ]);

    Livewire::actingAs($owner)
        ->test('pages::frontend.wallet')
        ->assertDontSee('SECRET-OTHER-USER')
        ->assertDontSee('777');
});

it('refreshes overview on customer-financial-invalidate only', function (): void {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => '10.00']);

    $component = Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSee(FrontendMoney::for($user)->format(10.00, 'USD', 2));

    $wallet->update(['balance' => '55.00']);

    $component
        ->dispatch('customer-financial-invalidate')
        ->assertSee(FrontendMoney::for($user)->format(55.00, 'USD', 2));
});

it('forged invalidate cannot select another user wallet', function (): void {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    Wallet::forUser($owner)->update(['balance' => '11.00']);
    Wallet::forUser($other)->update(['balance' => '999.00']);

    Livewire::actingAs($owner)
        ->test('pages::frontend.wallet')
        ->dispatch('customer-financial-invalidate', userId: $other->id)
        ->assertSee(FrontendMoney::for($owner)->format(11.00, 'USD', 2))
        ->assertDontSee(FrontendMoney::for($owner)->format(999.00, 'USD', 2));
});
