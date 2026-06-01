<?php

use App\Models\Category;
use App\Models\Package;
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
});

test('packages page renders for authenticated user', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');

    $this->actingAs($user)
        ->get('/packages')
        ->assertOk()
        ->assertSee('data-test="packages-page"', false);
});

test('packages page lists existing packages', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $package = Package::factory()->create([
        'name' => 'Gold Pack',
    ]);

    $this->actingAs($user)
        ->get('/packages')
        ->assertOk()
        ->assertSee($package->name);
});

test('package can be created from manager form', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $category = Category::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.packages.index')
        ->set('packageCategoryId', $category->id)
        ->set('packageName', 'Starter Pack')
        ->set('packageDescription', 'Starter description')
        ->set('packageOrder', 1)
        ->set('packageIsActive', true)
        ->call('savePackage')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('packages', [
        'name' => 'Starter Pack',
        'slug' => 'starter-pack',
    ]);
});

test('package can be created without description', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $category = Category::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.packages.index')
        ->set('packageCategoryId', $category->id)
        ->set('packageName', 'No Description Pack')
        ->set('packageDescription', null)
        ->set('packageOrder', 2)
        ->set('packageIsActive', true)
        ->call('savePackage')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('packages', [
        'name' => 'No Description Pack',
        'description' => null,
    ]);
});

test('package order must be unique', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $category = Category::factory()->create();

    Package::factory()->create([
        'category_id' => $category->id,
        'order' => 6,
    ]);

    $this->actingAs($user);

    Livewire::test('pages::backend.packages.index')
        ->set('packageCategoryId', $category->id)
        ->set('packageName', 'Duplicate Order')
        ->set('packageDescription', 'Trying to reuse order')
        ->set('packageOrder', 6)
        ->set('packageIsActive', true)
        ->call('savePackage')
        ->assertHasErrors(['packageOrder' => 'unique']);
});

test('packages page shows order range placeholder from database', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $category = Category::factory()->create();

    Package::factory()->create([
        'category_id' => $category->id,
        'order' => 2,
    ]);

    Package::factory()->create([
        'category_id' => $category->id,
        'order' => 8,
    ]);

    $this->actingAs($user)
        ->get('/packages')
        ->assertOk()
        ->assertSee(__('messages.order_range_placeholder', ['min' => 2, 'max' => 8]));
});

test('package fulfillment provider can be saved as browser automation', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $category = Category::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.packages.index')
        ->set('packageCategoryId', $category->id)
        ->set('packageName', 'Automated Pack')
        ->set('packageOrder', 3)
        ->set('packageIsActive', true)
        ->set('packageFulfillmentProvider', 'browser:acme')
        ->call('savePackage')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('packages', [
        'name' => 'Automated Pack',
        'fulfillment_provider' => 'browser:acme',
    ]);
});

test('package api can be saved for browser automation packages', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $category = Category::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.packages.index')
        ->set('packageCategoryId', $category->id)
        ->set('packageName', 'Wasim Pack')
        ->set('packageOrder', 5)
        ->set('packageIsActive', true)
        ->set('packageFulfillmentProvider', 'browser:wasim')
        ->set('packageApi', '/Customer/Category/games')
        ->call('savePackage')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('packages', [
        'name' => 'Wasim Pack',
        'package_api' => '/Customer/Category/games',
    ]);
});

test('package fulfillment provider is stored as null for manual', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $category = Category::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.packages.index')
        ->set('packageCategoryId', $category->id)
        ->set('packageName', 'Manual Pack')
        ->set('packageOrder', 4)
        ->set('packageIsActive', true)
        ->set('packageFulfillmentProvider', '')
        ->call('savePackage')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('packages', [
        'name' => 'Manual Pack',
        'fulfillment_provider' => null,
    ]);
});

test('editing package loads fulfillment provider into form', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $package = Package::factory()->create([
        'fulfillment_provider' => 'browser:acme',
    ]);

    $this->actingAs($user);

    Livewire::test('pages::backend.packages.index')
        ->call('startEditPackage', $package->id)
        ->assertSet('packageFulfillmentProvider', 'browser:acme');
});

test('package requirement can be added to selected package', function () {
    $user = User::factory()->create();
    $user->assignRole('admin');
    $package = Package::factory()->create();

    $this->actingAs($user);

    Livewire::test('pages::backend.packages.index')
        ->set('selectedPackageId', $package->id)
        ->set('requirementKey', 'id')
        ->set('requirementLabel', 'Player ID')
        ->set('requirementType', 'number')
        ->set('requirementIsRequired', true)
        ->set('requirementValidationRules', 'required|numeric')
        ->set('requirementOrder', 1)
        ->call('saveRequirement')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('package_requirements', [
        'package_id' => $package->id,
        'key' => 'id',
        'label' => 'Player ID',
        'type' => 'number',
        'is_required' => true,
        'order' => 1,
    ]);
});
