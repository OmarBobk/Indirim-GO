<?php

use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\BuyNowLoginIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('redirects guests to login and stores buy-now intent', function (): void {
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->for($package)->create(['is_active' => true]);

    Livewire::test('main.buy-now-modal')
        ->call('openBuyNow', $product->id)
        ->assertRedirect(route('login'));

    expect(session()->get(BuyNowLoginIntent::SESSION_KEY))->toMatchArray([
        'product_id' => $product->id,
    ]);
});

it('restores buy-now intent after login on mount', function (): void {
    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->for($package)->create([
        'is_active' => true,
        'name' => 'Restored Product',
    ]);

    BuyNowLoginIntent::store(['product_id' => $product->id, 'quantity' => 3]);

    Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->assertSet('showBuyNowModal', true)
        ->assertSet('buyNowProductId', $product->id)
        ->assertSet('buyNowQuantity', 3)
        ->assertSee('Restored Product');

    expect(session()->has(BuyNowLoginIntent::SESSION_KEY))->toBeFalse();
});

it('cart page exposes sticky checkout affordances when prices are visible', function (): void {
    WebsiteSetting::instance()->update(['prices_visible' => true]);

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('cart'))
        ->assertOk()
        ->assertSee('data-test="cart-sticky-checkout"', false)
        ->assertSee('data-test="cart-affordability"', false)
        ->assertSee(__('messages.purchase_instant_delivery'));
});
