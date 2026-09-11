<?php

use App\Enums\ProductAmountMode;
use App\Models\Package;
use App\Models\PackageRequirement;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('loads custom amount mode and pricing fields for package overlay products', function (): void {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 0,
        'retail_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create([
        'is_active' => true,
        'name' => 'Overlay Package',
    ]);

    Product::factory()->for($package)->create([
        'name' => 'Custom Amount Row',
        'is_active' => true,
        'order' => 1,
        'amount_mode' => ProductAmountMode::Custom,
        'amount_unit_label' => 'Crystal',
        'custom_amount_min' => 1000,
        'custom_amount_max' => 500_000,
        'custom_amount_step' => 500,
        'entry_price' => 0.01,
    ]);

    $component = Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openPackageOverlay', $package->id);

    $rows = $component->get('packageProducts');
    expect($rows)->toHaveCount(1)
        ->and($rows[0]['amount_mode'])->toBe('custom')
        ->and($rows[0]['custom_amount_min'])->toBe(1000);

    $live = $component->get('packageOverlayLivePrices');
    $productId = (int) $rows[0]['id'];
    expect($live[$productId]['final'] ?? null)->not->toBeNull()
        ->and($live[$productId]['per_unit'] ?? null)->not->toBeNull();

    $component->assertSee('Custom Amount Row')
        ->assertSee(__('messages.amount'))
        ->assertSee(__('messages.unit_price'))
        ->assertSee(__('messages.estimated_total'));
});

it('shows package products list when opening package overlay', function () {
    $package = Package::factory()->create([
        'is_active' => true,
        'name' => 'Soul Crystal',
    ]);

    Product::factory()->for($package)->create([
        'name' => 'Soul 1000 Crystal',
        'is_active' => true,
        'order' => 1,
    ]);

    Livewire::test('main.buy-now-modal')
        ->call('openPackageOverlay', $package->id)
        ->assertSet('showPackageProducts', true)
        ->assertSet('showBuyNowModal', true)
        ->assertSet('selectedPackageName', 'Soul Crystal')
        ->assertSee('Soul 1000 Crystal')
        ->assertDontSee(__('messages.no_products_yet'));
});

it('shows no products yet when package has no active products', function () {
    $package = Package::factory()->create([
        'is_active' => true,
        'name' => 'Empty Package',
    ]);

    Livewire::test('main.buy-now-modal')
        ->call('openPackageOverlay', $package->id)
        ->assertSet('showPackageProducts', true)
        ->assertSee(__('messages.no_products_yet'));
});

it('allows buy now submit for client tier pricing when server per unit rate is unset', function (): void {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 0,
        'retail_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true, 'name' => 'Pkg']);
    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'player_id',
        'label' => 'Player ID',
        'type' => 'string',
        'is_required' => true,
        'validation_rules' => 'required|string',
        'order' => 1,
    ]);
    $product = Product::factory()->for($package)->create([
        'name' => 'Custom With Req',
        'is_active' => true,
        'order' => 1,
        'amount_mode' => ProductAmountMode::Custom,
        'custom_amount_min' => 10_000,
        'custom_amount_max' => 1_000_000_000,
        'custom_amount_step' => 1,
        'entry_price' => 0.00012,
    ]);

    $component = Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openPackageOverlay', $package->id)
        ->call('openBuyNow', $product->id, true, 1);

    expect($component->instance()->buyNowClientPricingContext()['client_pricable'] ?? false)->toBeTrue();

    $component->set('buyNowFinalPerUnitRate', null);
    $component->set('buyNowLineFinalPrice', null);
    $component->set('buyNowRequirements.player_id', '1231231231');

    expect($component->get('buyNowCanSubmit'))->toBeTrue();
});

it('shows requirement fields when opening buy now from package overlay', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create([
        'is_active' => true,
        'name' => 'Package With Requirements',
    ]);

    PackageRequirement::factory()->create([
        'package_id' => $package->id,
        'key' => 'player_id',
        'label' => 'Player ID',
        'type' => 'string',
        'is_required' => true,
        'validation_rules' => 'required|string',
        'order' => 1,
    ]);

    $product = Product::factory()->for($package)->create([
        'name' => 'Product One',
        'is_active' => true,
        'order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openPackageOverlay', $package->id)
        ->assertSet('showPackageProducts', true)
        ->call('openBuyNow', $product->id, true, 1)
        ->assertSet('showPackageProducts', false)
        ->assertSet('buyNowProductId', $product->id)
        ->assertSee('Player ID')
        ->assertSee('Product One');
});

it('reprice buy now custom amount on blur matches server tiers like cart reprice', function (): void {
    PricingRule::query()->delete();
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 100,
        'wholesale_percentage' => 0,
        'retail_percentage' => 10,
        'priority' => 0,
        'is_active' => true,
    ]);
    PricingRule::create([
        'min_price' => 100,
        'max_price' => 1000,
        'wholesale_percentage' => 0,
        'retail_percentage' => 5,
        'priority' => 1,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->for($package)->create([
        'is_active' => true,
        'amount_mode' => ProductAmountMode::Custom,
        'amount_unit_label' => 'Unit',
        'custom_amount_min' => 1000,
        'custom_amount_max' => 1_000_000,
        'custom_amount_step' => 1000,
        'entry_price' => 0.01,
    ]);

    $component = Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openBuyNow', $product->id, false, 1);

    expect((float) $component->get('buyNowLineFinalPrice'))->toBe(11.0)
        ->and((float) $component->get('buyNowFinalPerUnitRate'))->toBe(0.011);

    $component->call('repriceBuyNowCustomAmount', 20_000);

    expect((float) $component->get('buyNowLineFinalPrice'))->toBe(210.0)
        ->and((float) $component->get('buyNowFinalPerUnitRate'))->toBe(0.0105)
        ->and($component->get('buyNowRequestedAmount'))->toBe(20_000);
});

it('computes live line total when opening buy now for custom amount product', function (): void {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 0,
        'retail_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->for($package)->create([
        'is_active' => true,
        'amount_mode' => ProductAmountMode::Custom,
        'amount_unit_label' => 'Crystal',
        'custom_amount_min' => 500,
        'custom_amount_max' => 1_000_000,
        'custom_amount_step' => 500,
        'entry_price' => 0.01,
    ]);

    $component = Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openBuyNow', $product->id, false, 1);

    $component->assertSet('buyNowRequestedAmount', 500);
    $component->assertSet('buyNowRequestedAmountInput', '500');
    expect($component->get('buyNowLineFinalPrice'))->toBe(5.0);
    expect($component->get('buyNowFinalPerUnitRate'))->toBe(0.01);
});

it('uses overlay custom amount when opening buy now from package list if valid', function (): void {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 0,
        'retail_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->for($package)->create([
        'is_active' => true,
        'amount_mode' => ProductAmountMode::Custom,
        'amount_unit_label' => 'Crystal',
        'custom_amount_min' => 500,
        'custom_amount_max' => 1_000_000,
        'custom_amount_step' => 500,
        'entry_price' => 0.01,
    ]);

    $component = Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openBuyNow', $product->id, true, 1, 2500);

    $component->assertSet('buyNowRequestedAmount', 2500)
        ->assertSet('showPackageProducts', false);

    expect((float) $component->get('buyNowLineFinalPrice'))->toBe(25.0);
});

it('falls back to minimum custom amount when overlay amount fails validation', function (): void {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 0,
        'retail_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->for($package)->create([
        'is_active' => true,
        'amount_mode' => ProductAmountMode::Custom,
        'custom_amount_min' => 500,
        'custom_amount_max' => 1_000_000,
        'custom_amount_step' => 500,
        'entry_price' => 0.01,
    ]);

    Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openBuyNow', $product->id, true, 1, 499)
        ->assertSet('buyNowRequestedAmount', 500);

    Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openBuyNow', $product->id, true, 1, 501)
        ->assertSet('buyNowRequestedAmount', 500);
});

it('shows no additional requirements message when package has no requirements', function () {
    $user = User::factory()->create();
    $package = Package::factory()->create([
        'is_active' => true,
        'name' => 'Package No Reqs',
    ]);

    $product = Product::factory()->for($package)->create([
        'name' => 'Simple Product',
        'is_active' => true,
        'order' => 1,
    ]);

    Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openPackageOverlay', $package->id)
        ->call('openBuyNow', $product->id, true, 1)
        ->assertSee(__('messages.no_additional_requirements'));
});

it('computes fixed buy now line total using total-based pricing', function (): void {
    PricingRule::query()->delete();
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 100,
        'wholesale_percentage' => 0,
        'retail_percentage' => 50,
        'priority' => 0,
        'is_active' => true,
    ]);
    PricingRule::create([
        'min_price' => 100,
        'max_price' => 1000,
        'wholesale_percentage' => 0,
        'retail_percentage' => 10,
        'priority' => 1,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->for($package)->create([
        'is_active' => true,
        'amount_mode' => ProductAmountMode::Fixed,
        'entry_price' => 90,
    ]);

    $component = Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openBuyNow', $product->id, false, 1)
        ->set('buyNowQuantity', 2);

    expect((float) $component->get('buyNowLineFinalPrice'))->toBe(198.0);
});

it('treats cleared fixed quantity as empty without error and clears line price until re-entered', function (): void {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 0,
        'retail_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->for($package)->create([
        'is_active' => true,
        'amount_mode' => ProductAmountMode::Fixed,
        'entry_price' => 10,
        'retail_price' => 12,
    ]);

    $component = Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openBuyNow', $product->id, false, 1);

    expect($component->get('buyNowLineFinalPrice'))->not->toBeNull();

    $component->set('buyNowQuantity', '');

    expect($component->get('buyNowQuantity'))->toBe('')
        ->and($component->get('buyNowLineFinalPrice'))->toBeNull();

    $component->assertStatus(200);

    $component->set('buyNowQuantity', 3);

    expect((float) $component->get('buyNowLineFinalPrice'))->toBeGreaterThan(0);
});

it('defaults quantity when openBuyNow omits optional quantity argument', function (): void {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 0,
        'retail_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);

    $user = User::factory()->create();
    $package = Package::factory()->create(['is_active' => true]);
    $product = Product::factory()->for($package)->create([
        'is_active' => true,
        'amount_mode' => ProductAmountMode::Fixed,
        'entry_price' => 5,
        'retail_price' => 6,
    ]);

    Livewire::actingAs($user)
        ->test('main.buy-now-modal')
        ->call('openBuyNow', $product->id, false, null)
        ->assertSet('buyNowQuantity', 1)
        ->assertStatus(200);
});

it('keeps package deep-link init off the remounting buy-now shell', function (): void {
    $source = file_get_contents(resource_path('views/components/main/⚡buy-now-modal.blade.php'));

    expect($source)->toContain('data-test="buy-now-overlay-host"')
        ->and($source)->toContain('__karmanPackageDeepLinkDone')
        ->and($source)->toContain('wire:key="buy-now-shell-');

    $hostPos = strpos($source, 'data-test="buy-now-overlay-host"');
    $deepLinkPos = strpos($source, '__karmanPackageDeepLinkDone');
    $shellKeyPos = strpos($source, 'wire:key="buy-now-shell-');

    expect($hostPos)->not->toBeFalse()
        ->and($deepLinkPos)->not->toBeFalse()
        ->and($shellKeyPos)->not->toBeFalse()
        ->and($hostPos)->toBeLessThan($deepLinkPos)
        ->and($deepLinkPos)->toBeLessThan($shellKeyPos);
});
