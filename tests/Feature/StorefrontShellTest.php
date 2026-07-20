<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\StorefrontShell;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest home renders mobile shell with guest bottom navigation', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('data-storefront-shell="root"', false);
    $response->assertSee('data-test="storefront-mobile-top"', false);
    $response->assertSee('data-test="storefront-bottom-nav"', false);
    $response->assertSee('data-test="bottom-nav-home"', false);
    $response->assertSee('data-test="bottom-nav-cart"', false);
    $response->assertSee('data-test="bottom-nav-login"', false);
    $response->assertSee('data-event="bottom-nav-home"', false);
    $response->assertSee('data-event="bottom-nav-cart"', false);
    $response->assertSee('data-event="bottom-nav-login"', false);
    $response->assertDontSee('data-test="bottom-nav-orders"', false);
    $response->assertDontSee('data-test="bottom-nav-wallet"', false);
    $response->assertDontSee('data-test="bottom-nav-account"', false);
    $response->assertSee('data-test="frontend-announcement-bar"', false);
});

test('authenticated home renders auth bottom navigation and hides announcement', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
    $response->assertSee('data-test="storefront-bottom-nav"', false);
    $response->assertSee('data-test="bottom-nav-home"', false);
    $response->assertSee('data-test="bottom-nav-orders"', false);
    $response->assertSee('data-test="bottom-nav-wallet"', false);
    $response->assertSee('data-test="bottom-nav-cart"', false);
    $response->assertSee('data-test="bottom-nav-account"', false);
    $response->assertSee('data-event="bottom-nav-wallet"', false);
    $response->assertSee('data-event="bottom-nav-orders"', false);
    $response->assertSee('data-event="wallet-chip"', false);
    $response->assertSee('data-test="wallet-balance"', false);
    $response->assertDontSee('data-test="bottom-nav-login"', false);
    $response->assertDontSee('data-test="frontend-announcement-bar"', false);
});

test('storefront shell resolves bottom nav from configuration', function () {
    expect(config('storefront.shell_max'))->toBe('lg');
    expect(config('storefront.bottom_nav.authenticated'))->toBeArray()->not->toBeEmpty();
    expect(config('storefront.bottom_nav.guest'))->toBeArray()->not->toBeEmpty();

    $guest = StorefrontShell::bottomNavItems();
    expect(collect($guest)->pluck('key')->all())->toBe(['home', 'cart', 'login']);

    $user = User::factory()->create();
    $this->actingAs($user);

    $auth = StorefrontShell::bottomNavItems();
    expect(collect($auth)->pluck('key')->all())->toBe(['home', 'orders', 'wallet', 'cart', 'account']);
    expect(collect($auth)->pluck('event')->all())->toBe([
        'bottom-nav-home',
        'bottom-nav-orders',
        'bottom-nav-wallet',
        'bottom-nav-cart',
        'bottom-nav-account',
    ]);
});

test('wallet page marks wallet bottom nav item active', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->get(route('wallet'));

    $response->assertOk();
    $response->assertSee('data-test="bottom-nav-wallet"', false);
    $response->assertSee('data-event="bottom-nav-wallet"', false);
    $response->assertSee('data-nav-active="true"', false);

    $items = StorefrontShell::bottomNavItems();
    $wallet = collect($items)->firstWhere('key', 'wallet');

    expect($wallet)->not->toBeNull()
        ->and($wallet['active'])->toBeTrue();
});

test('desktop header shell marker remains available for large screens', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('data-storefront-shell="desktop-header"', false);
    $response->assertSee('data-storefront-shell="main"', false);
});
