<?php

use App\Enums\ProductAmountMode;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::firstOrCreate(['name' => 'admin']);
    $role->syncPermissions([
        Permission::firstOrCreate(['name' => 'manage_products']),
    ]);
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
});

test('products page renders for authenticated user', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee('data-test="products-page"', false);
});

test('products page lists existing products', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $product = Product::factory()->create([
        'name' => 'Bronze Product',
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee($product->name);
});

test('product can be created from manager form', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $package = Package::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.products.index')
        ->set('productPackageId', $package->id)
        ->set('productSerial', 'SER-10001')
        ->set('productName', 'Starter Product')
        ->set('productEntryPrice', '10.50')
        ->set('productOrder', 1)
        ->set('productIsActive', true)
        ->call('saveProduct')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('products', [
        'name' => 'Starter Product',
        'slug' => 'starter-product',
        'serial' => 'SER-10001',
        'entry_price' => 10.50,
        'amount_mode' => ProductAmountMode::Fixed->value,
    ]);
});

test('product api can be saved from manager form', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $package = Package::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.products.index')
        ->set('productPackageId', $package->id)
        ->set('productName', 'Wasim Product')
        ->set('productEntryPrice', '12.00')
        ->set('productOrder', 2)
        ->set('productIsActive', true)
        ->set('productApi', 'Customer/Home/ProductRequest?productId=274')
        ->call('saveProduct')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('products', [
        'name' => 'Wasim Product',
        'product_api' => 'Customer/Home/ProductRequest?productId=274',
    ]);
});

test('product can be created as custom amount from manager form', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $package = Package::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.products.index')
        ->set('productPackageId', $package->id)
        ->set('productSerial', 'CUS-10001')
        ->set('productName', 'Data pack')
        ->set('productAmountMode', ProductAmountMode::Custom->value)
        ->set('productAmountUnitLabel', 'GB')
        ->set('productCustomAmountMin', 1)
        ->set('productCustomAmountMax', 500)
        ->set('productCustomAmountStep', 5)
        ->set('productEntryPrice', '0.50')
        ->set('productOrder', 5)
        ->set('productIsActive', true)
        ->call('saveProduct')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('products', [
        'name' => 'Data pack',
        'slug' => 'data-pack',
        'serial' => 'CUS-10001',
        'amount_mode' => ProductAmountMode::Custom->value,
        'amount_unit_label' => 'GB',
        'custom_amount_min' => 1,
        'custom_amount_max' => 500,
        'custom_amount_step' => 5,
        'entry_price' => 0.50,
    ]);
});

test('custom amount product rejects zero entry price', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $package = Package::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.products.index')
        ->set('productPackageId', $package->id)
        ->set('productName', 'Bad custom')
        ->set('productAmountMode', ProductAmountMode::Custom->value)
        ->set('productAmountUnitLabel', 'GB')
        ->set('productCustomAmountMin', 1)
        ->set('productCustomAmountMax', 100)
        ->set('productEntryPrice', '0')
        ->set('productOrder', 1)
        ->set('productIsActive', true)
        ->call('saveProduct')
        ->assertHasErrors(['productEntryPrice']);
});

test('products table shows custom amount product details', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $package = Package::factory()->create();

    Product::factory()->create([
        'package_id' => $package->id,
        'serial' => 'CUS-TABLE-01',
        'name' => 'Custom Table Product',
        'slug' => 'custom-table-product',
        'amount_mode' => ProductAmountMode::Custom->value,
        'amount_unit_label' => 'GB',
        'custom_amount_min' => 1,
        'custom_amount_max' => 500,
        'custom_amount_step' => 5,
        'entry_price' => 0.5,
        'is_active' => true,
        'order' => 10,
    ]);

    $this->actingAs($user)
        ->get('/products')
        ->assertOk()
        ->assertSee(__('messages.amount_mode_custom'))
        ->assertSee(__('messages.custom_amount_min').': 1', false)
        ->assertSee(__('messages.custom_amount_max').': 500', false)
        ->assertSee(__('messages.custom_amount_step').': 5', false);
});

test('products can be filtered by package', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $targetPackage = Package::factory()->create(['name' => 'Target Package']);
    $otherPackage = Package::factory()->create(['name' => 'Other Package']);

    $targetProduct = Product::factory()->create([
        'package_id' => $targetPackage->id,
        'name' => 'Target Product',
    ]);

    $otherProduct = Product::factory()->create([
        'package_id' => $otherPackage->id,
        'name' => 'Other Product',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::backend.products.index')
        ->set('packageFilter', (string) $targetPackage->id)
        ->call('applyFilters')
        ->assertSee($targetProduct->name)
        ->assertDontSee($otherProduct->name);
});
