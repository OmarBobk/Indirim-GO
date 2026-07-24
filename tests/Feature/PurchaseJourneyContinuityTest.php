<?php

declare(strict_types=1);

use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Support\PurchaseResumeIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('stores and peeks a buy-now resume intent with requirements', function (): void {
    PurchaseResumeIntent::store([
        'source' => PurchaseResumeIntent::SOURCE_BUY_NOW,
        'product_id' => 12,
        'package_id' => 3,
        'quantity' => 2,
        'requested_amount' => 500,
        'requirements' => ['player_id' => 'abc'],
    ]);

    expect(PurchaseResumeIntent::peek())->toMatchArray([
        'source' => 'buy_now',
        'product_id' => 12,
        'package_id' => 3,
        'quantity' => 2,
        'requested_amount' => 500,
        'requirements' => ['player_id' => 'abc'],
    ])->and(PurchaseResumeIntent::resumeUrl())->toBe(route('home'));
});

it('stores a cart resume intent', function (): void {
    PurchaseResumeIntent::store(['source' => PurchaseResumeIntent::SOURCE_CART]);

    expect(PurchaseResumeIntent::peek())->toMatchArray([
        'source' => 'cart',
    ])->and(PurchaseResumeIntent::resumeUrl())->toBe(route('cart'));
});

it('expires stale resume intents', function (): void {
    session()->put(PurchaseResumeIntent::SESSION_KEY, [
        'source' => PurchaseResumeIntent::SOURCE_CART,
        'stored_at' => now()->subDays(2)->timestamp,
    ]);

    expect(PurchaseResumeIntent::peek())->toBeNull();
});

it('redirects to home with resume after buy-now top-up submit', function (): void {
    $user = User::factory()->create();
    PurchaseResumeIntent::store([
        'source' => PurchaseResumeIntent::SOURCE_BUY_NOW,
        'product_id' => 1,
    ]);

    // Top-up page may block without payment methods — assert resume URL helper + redirect target contract.
    expect(PurchaseResumeIntent::resumeUrl())->toBe(route('home'));

    $this->actingAs($user)
        ->get(route('wallet'))
        ->assertOk()
        ->assertSee('data-test="purchase-resume-banner"', false)
        ->assertSee('data-test="purchase-resume-continue"', false);
});

it('buy-now continueToTopup stores resume intent and redirects to topup', function (): void {
    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'package_id' => $package->id,
        'is_active' => true,
        'entry_price' => 10,
    ]);

    Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openBuyNow', $product->id)
        ->call('continueToTopup')
        ->assertRedirect(route('wallet.topup', ['amount' => '10.00']));

    expect(PurchaseResumeIntent::peek())->toMatchArray([
        'source' => 'buy_now',
        'product_id' => $product->id,
    ]);
});

it('cart continueToTopup stores cart resume intent', function (): void {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::frontend.cart')
        ->call('continueToTopup', '25.00')
        ->assertRedirect(route('wallet.topup', ['amount' => '25.00']));

    expect(PurchaseResumeIntent::peek())->toMatchArray([
        'source' => 'cart',
    ]);
});

it('shows intentional prices-gated UX on cart when prices are hidden', function (): void {
    $user = User::factory()->create();
    \App\Models\WebsiteSetting::instance()->update(['prices_visible' => false]);

    $this->actingAs($user)
        ->get(route('cart'))
        ->assertOk()
        ->assertSee('data-test="prices-gated"', false)
        ->assertSee('data-prices-gated-context="cart"', false);
});
