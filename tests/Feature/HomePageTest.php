<?php

use App\Models\Category;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('guest homepage renders marketing sections and packages', function () {
    $package = Package::factory()->create([
        'is_active' => true,
        'order' => 1,
        'image' => null,
        'name' => 'Steam Wallet',
    ]);

    Product::factory()->for($package)->create([
        'name' => 'Steam 10 USD',
        'entry_price' => 10,
        'is_active' => true,
        'order' => 1,
    ]);

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('data-test="guest-home"', false);
    $response->assertSee('data-test="cart-dropdown"', false);
    $response->assertSee('data-section="homepage-marquee"', false);
    $response->assertSee('data-section="homepage-promos"', false);
    $response->assertSee('data-section="homepage-section-of-categories"', false);
    $response->assertSee('data-section="homepage-section-of-packages"', false);
    $response->assertSee('Steam Wallet');
    $response->assertSee(__('messages.packages'));
});

test('homepage circular slider shows packages and placeholder', function () {
    $category = Category::factory()->create(['order' => 1]);

    Package::factory()
        ->count(21)
        ->for($category)
        ->sequence(fn ($sequence) => [
            'name' => 'Package '.($sequence->index + 1),
            'order' => $sequence->index + 1,
            'is_active' => true,
            'image' => $sequence->index === 0 ? null : 'images/packages/package-'.$sequence->index.'.jpg',
        ])
        ->create();

    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('Package 1');
    $response->assertSee('Package 19');
    $response->assertSee(asset('images/icons/category-placeholder.svg'));
});

test('frontend head exposes currency preference globals', function () {
    $user = User::factory()->create([
        'preferred_currency' => 'TRY',
    ]);

    $response = $this->actingAs($user)->get('/');

    $response->assertOk();
    $response->assertSee('window.Laravel.preferredCurrency', false);
    $response->assertSee('"TRY"', false);
    $response->assertSee('window.Laravel.usdTryRate', false);
});
