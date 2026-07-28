<?php

declare(strict_types=1);

use App\Enums\CreditFacilityStatus;
use App\Enums\WalletType;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebsiteSetting;
use App\Support\FrontendMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::query()->firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

test('wallet page highlights available to spend for prepaid balance', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update(['balance' => '75.50']);

    $money = FrontendMoney::for($user);
    $available = $money->format(75.50, 'USD', 2);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSee(__('messages.wallet_available_to_spend'))
        ->assertSeeHtml('data-test="wallet-available-to-spend"')
        ->assertSeeHtml('data-test="wallet-prepaid-balance"')
        ->assertSeeHtml('data-wallet-tone="positive"')
        ->assertSeeHtml('text-emerald-700')
        ->assertSee($available)
        ->assertSee(__('messages.wallet_prepaid_balance'))
        ->assertDontSee(__('messages.wallet_credit_section'))
        ->assertDontSee(__('messages.wallet_you_owe'));
});

test('wallet page shows credit limit beside prepaid balance when facility is active', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $money = FrontendMoney::for($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSeeHtml('data-test="wallet-prepaid-balance"')
        ->assertSee($money->format(10.00, 'USD', 2))
        ->assertSeeHtml('data-test="wallet-credit-limit-summary"')
        ->assertSee($money->format(100.00, 'USD', 2))
        ->assertSee($money->format(110.00, 'USD', 2))
        ->assertSee(__('messages.wallet_credit_section'));
});

test('header shows green prepaid balance and credit limit hint when facility is active', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.87',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $money = FrontendMoney::for($user);
    $balance = $money->format(10.87, 'USD', 2);
    $limitAmount = $money->format(100.00, 'USD', 2);
    $limitHint = __('messages.wallet_nav_limit', ['amount' => $limitAmount]);
    $ctaBadge = $balance.' · '.$limitHint;

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-test="wallet-balance"')
        ->assertSeeHtml('data-wallet-tone="positive"')
        ->assertSeeHtml('text-emerald-700')
        ->assertSee($balance)
        ->assertSee($limitAmount)
        ->assertSeeHtml('data-test="wallet-nav-credit-hint"')
        ->assertSeeHtml('>'.e($limitAmount).'</span>')
        ->assertDontSeeHtml('hidden text-[10px] font-medium text-zinc-500 dark:text-zinc-400 lg:inline')
        ->assertSeeHtml('data-test="wallet-chrome-summary"')
        ->assertSeeHtml('data-test="wallet-chrome-open"')
        ->assertSeeHtml('data-wallet-cta-badge="'.e($ctaBadge).'"')
        ->assertSeeHtml('data-test="wallet-nav-amount"');
});

test('header shows red signed debt when wallet is overdrawn', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '-10.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $money = FrontendMoney::for($user);
    $signedDebt = $money->format(-10.00, 'USD', 2);
    $availableAmount = $money->format(90.00, 'USD', 2);
    $availableHint = __('messages.wallet_nav_available', [
        'amount' => $availableAmount,
    ]);
    $ctaBadge = $signedDebt.' · '.$availableHint;

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-wallet-tone="debt"')
        ->assertSeeHtml('text-red-700')
        ->assertSee($signedDebt)
        ->assertSee($availableAmount)
        ->assertSeeHtml('data-test="wallet-nav-credit-hint"')
        ->assertSeeHtml('>'.e($availableAmount).'</span>')
        ->assertSeeHtml('data-wallet-cta-badge="'.e($ctaBadge).'"')
        ->assertSee(__('messages.wallet_credit_limit_label'))
        ->assertSee($money->format(100.00, 'USD', 2))
        ->assertDontSeeHtml('data-wallet-tone="positive"');
});

test('header treats zero balance as neutral tone', function () {
    $user = User::factory()->create(['locale' => 'en']);
    Wallet::forUser($user)->update(['balance' => '0.00']);

    $money = FrontendMoney::for($user);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-wallet-tone="zero"')
        ->assertSee($money->format(0.00, 'USD', 2))
        ->assertSeeHtml('text-zinc-700')
        ->assertDontSeeHtml('data-test="wallet-nav-credit-hint"')
        ->assertDontSeeHtml('data-wallet-tone="debt"');
});

test('header does not show credit limit when facility is off', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '25.00',
        'credit_enabled' => false,
        'credit_limit' => '500.00',
        'credit_status' => null,
    ]);

    $money = FrontendMoney::for($user);
    $balance = $money->format(25.00, 'USD', 2);
    $limitHint = __('messages.wallet_nav_limit', ['amount' => $money->format(500.00, 'USD', 2)]);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSee($balance)
        ->assertSeeHtml('data-wallet-cta-badge="'.e($balance).'"')
        ->assertDontSee($limitHint)
        ->assertDontSeeHtml('data-test="wallet-nav-credit-hint"')
        ->assertDontSee(__('messages.wallet_credit_limit_label'));
});

test('header surfaces active credit limit at zero balance for mobile chrome', function () {
    $user = User::factory()->create(['locale' => 'en']);
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '0.00',
        'credit_enabled' => true,
        'credit_limit' => '250.00',
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $money = FrontendMoney::for($user);
    $balance = $money->format(0.00, 'USD', 2);
    $limitAmount = $money->format(250.00, 'USD', 2);
    $limitHint = __('messages.wallet_nav_limit', ['amount' => $limitAmount]);

    $html = $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-wallet-tone="zero"')
        ->assertSee($balance)
        ->assertSee($limitAmount)
        ->assertSeeHtml('data-test="wallet-nav-credit-hint"')
        ->assertSeeHtml('>'.e($limitAmount).'</span>')
        ->assertSeeHtml('data-test="wallet-chrome-summary"')
        ->assertSee(__('messages.wallet_credit_limit_label'))
        ->assertSeeHtml('data-wallet-cta-badge="'.e($balance.' · '.$limitHint).'"')
        ->assertSeeHtml('data-storefront-shell="mobile-top"')
        ->assertSeeHtml('data-chrome-surface="desktop-header"')
        ->getContent();

    expect(substr_count($html, 'data-test="wallet-balance"'))->toBe(2)
        ->and(substr_count($html, 'data-storefront-shell="mobile-top"'))->toBe(1);
});

test('mobile top bar shows wallet amount chrome for authenticated customers', function () {
    $user = User::factory()->create(['locale' => 'en']);
    Wallet::forUser($user)->update(['balance' => '42.00']);

    $money = FrontendMoney::for($user);
    $balance = $money->format(42.00, 'USD', 2);

    $this->actingAs($user)
        ->get(route('home'))
        ->assertOk()
        ->assertSeeHtml('data-test="storefront-mobile-top"')
        ->assertSeeHtml('data-storefront-shell="mobile-top"')
        ->assertSeeHtml('data-event="top-nav-wallet"')
        ->assertSeeHtml('data-test="wallet-nav-amount"')
        ->assertSee($balance);
});

test('wallet page shows owed amount and credit facility when overdrawn with active credit', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '-40.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $money = FrontendMoney::for($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSee(__('messages.wallet_you_owe'))
        ->assertSeeHtml('data-test="wallet-outstanding-debt"')
        ->assertSee($money->format(40.00, 'USD', 2))
        ->assertSee(__('messages.wallet_you_owe_hint'))
        ->assertSee($money->format(60.00, 'USD', 2))
        ->assertSee(__('messages.wallet_credit_section'))
        ->assertSee(__('messages.wallet_credit_status_active'))
        ->assertSee(__('messages.wallet_available_credit_label'))
        ->assertSee(__('messages.wallet_credit_terms_net', ['days' => 30]))
        ->assertSeeHtml('data-test="wallet-credit-facility"')
        ->assertDontSee(__('messages.wallet_prepaid_balance'));
});

test('wallet page shows paused credit without implying overdraft is allowed', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '20.00',
        'credit_enabled' => true,
        'credit_limit' => '150.00',
        'payment_terms_days' => 15,
        'credit_status' => CreditFacilityStatus::Suspended,
    ]);

    $money = FrontendMoney::for($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.wallet')
        ->assertSee(__('messages.wallet_credit_section'))
        ->assertSee(__('messages.wallet_credit_status_suspended'))
        ->assertSee(__('messages.wallet_credit_suspended_hint'))
        ->assertDontSee(__('messages.wallet_credit_active_hint'))
        ->assertDontSee(__('messages.wallet_available_credit_label'))
        ->assertSee($money->format(20.00, 'USD', 2))
        ->assertDontSee($money->format(170.00, 'USD', 2));
});

test('cart shows available to spend from wallet helpers', function () {
    WebsiteSetting::instance()->update(['prices_visible' => true]);

    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $money = FrontendMoney::for($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->assertSee(__('messages.cart_available_to_spend'))
        ->assertSeeHtml('data-test="cart-affordability"')
        ->assertSeeHtml('data-test="purchase-affordability"')
        ->assertSee($money->format(110.00, 'USD', 2))
        ->assertDontSeeHtml('data-test="cart-amount-you-owe"');
});

test('cart shows amount owed when wallet is overdrawn', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '-25.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $money = FrontendMoney::for($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->assertSeeHtml('data-test="cart-amount-you-owe"')
        ->assertSee(__('messages.cart_amount_you_owe'))
        ->assertSee($money->format(25.00, 'USD', 2))
        ->assertSee($money->format(75.00, 'USD', 2));
});

test('checkout succeeds when prepaid balance is short but active credit covers the total', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('checkout', [[
            'id' => $product->id,
            'quantity' => 1,
        ]])
        ->assertSet('checkoutError', null)
        ->assertSet('checkoutNeedsFunds', false)
        ->assertSet('checkoutSuccess', fn (?string $value) => filled($value));

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(-40.0)
        ->and($wallet->isOverdrawn())->toBeTrue();
});

test('checkout fails clearly when spend exceeds available including credit', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.00',
        'credit_enabled' => true,
        'credit_limit' => '20.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    $expected = __('messages.wallet_spend_insufficient', [
        'available' => '30.00',
        'currency' => config('billing.currency', 'USD'),
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('checkout', [[
            'id' => $product->id,
            'quantity' => 1,
        ]])
        ->assertSet('checkoutError', $expected)
        ->assertSet('checkoutNeedsFunds', true)
        ->assertSee(__('messages.cart_need_more_funds'));

    expect(Wallet::query()->where('type', WalletType::Customer)->count())->toBe(1);
});

test('checkout fails when credit is suspended even if limit would cover purchase', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '10.00',
        'credit_enabled' => true,
        'credit_limit' => '500.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Suspended,
    ]);

    $package = Package::factory()->create();
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'entry_price' => 50,
    ]);

    $expected = __('messages.wallet_spend_insufficient', [
        'available' => '10.00',
        'currency' => config('billing.currency', 'USD'),
    ]);

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('checkout', [[
            'id' => $product->id,
            'quantity' => 1,
        ]])
        ->assertSet('checkoutError', $expected)
        ->assertSet('checkoutNeedsFunds', true);

    $wallet->refresh();
    expect((float) $wallet->balance)->toBe(10.0);
});

test('profile wallet card shows available to spend not raw negative balance', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '-25.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $money = FrontendMoney::for($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.profile')
        ->assertSeeHtml('data-test="profile-wallet-debt"')
        ->assertSee(__('messages.wallet_you_owe'))
        ->assertSee($money->format(25.00, 'USD', 2))
        ->assertSeeHtml('data-test="profile-wallet-available"')
        ->assertSee($money->format(75.00, 'USD', 2))
        ->assertSeeHtml('data-test="profile-wallet-credit-limit"')
        ->assertDontSee($money->format(-25.00, 'USD', 2));
});

test('profile wallet card does not claim credit spend when facility is disabled', function () {
    $user = User::factory()->create();
    $wallet = Wallet::forUser($user);
    $wallet->update([
        'balance' => '40.00',
        'credit_enabled' => false,
        'credit_limit' => '200.00',
        'credit_status' => null,
    ]);

    $money = FrontendMoney::for($user);

    Livewire::actingAs($user)
        ->test('pages::frontend.profile')
        ->assertSeeHtml('data-test="profile-wallet-balance"')
        ->assertSeeHtml('text-emerald-700')
        ->assertSeeHtml('data-test="profile-wallet-available"')
        ->assertSee($money->format(40.00, 'USD', 2))
        ->assertDontSeeHtml('data-test="profile-wallet-debt"')
        ->assertDontSeeHtml('data-test="profile-wallet-credit-limit"')
        ->assertDontSee($money->format(240.00, 'USD', 2));
});
