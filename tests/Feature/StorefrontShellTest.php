<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\User;
use App\Support\StorefrontShell;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;

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
    $response->assertSee('data-test="mobile-notifications"', false);
    $response->assertDontSee('data-event="wallet-chip"', false);
    $response->assertDontSee('data-test="bottom-nav-login"', false);
    $response->assertDontSee('data-test="frontend-announcement-bar"', false);
    $response->assertSee('data-test="storefront-browse-nav"', false);
    $response->assertDontSee('data-test="wallet-add-sufficient"', false);
});

test('mobile notifications badge appears when unread notifications exist', function () {
    $user = User::factory()->create();

    DatabaseNotification::query()->create([
        'id' => (string) Str::uuid(),
        'type' => 'App\\Notifications\\TestNotification',
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->id,
        'data' => ['title' => 'Alert', 'message' => 'Unread'],
        'read_at' => null,
    ]);

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
    $response->assertSee('data-test="mobile-notifications-badge"', false);
    $response->assertSee('data-test="bottom-nav-account-badge"', false);
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
    expect(collect($auth)->firstWhere('key', 'account')['route'])->toBe('account');
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

test('account hub marks account bottom nav active', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->get(route('account'))->assertOk();

    $account = collect(StorefrontShell::bottomNavItems())->firstWhere('key', 'account');

    expect($account['active'])->toBeTrue();
});

test('desktop browse nav lists home and categories without app destinations', function () {
    $user = User::factory()->create();
    Category::factory()->create(['name' => 'Phones', 'is_active' => true, 'parent_id' => null]);

    $response = $this->actingAs($user)->get(route('home'));

    $response->assertOk();
    $response->assertSee('data-test="browse-nav-home"', false);
    $response->assertSee('Phones');
    $response->assertDontSee('data-test="wallet-add-sufficient"', false);

    $browse = StorefrontShell::browseNavItems();
    expect(collect($browse)->pluck('key')->all())->toContain('home');
    expect(collect($browse)->contains(fn ($item) => str_starts_with($item['key'], 'category-')))->toBeTrue();
});

test('desktop header shell marker remains available for large screens', function () {
    $response = $this->get(route('home'));

    $response->assertOk();
    $response->assertSee('data-storefront-shell="desktop-header"', false);
    $response->assertSee('data-storefront-shell="main"', false);
    $response->assertSee('data-storefront-shell="browse-nav"', false);
});
