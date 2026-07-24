<?php

use App\Models\Category;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('header includes alpine package search', function () {
    $response = $this->get('/');

    $response->assertOk();
    $response->assertSee('storefrontPackageSearch', false);
    $response->assertSee(__('main.search_packages_placeholder'));
});

test('storefront header uses locale brand logo images', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('light_en_logo.png', false)
        ->assertSee('dark_en_logo.png', false);

    $this->withSession(['locale' => 'ar'])
        ->get('/')
        ->assertOk()
        ->assertSee('light_ar_logo.png', false)
        ->assertSee('dark_ar_logo.png', false);
});

test('package search api returns only active packages with active products', function () {
    $category = Category::factory()->create(['is_active' => true]);

    $matching = Package::factory()->for($category)->create([
        'name' => 'Steam Gift Card',
        'description' => 'Digital delivery',
        'is_active' => true,
    ]);

    Product::factory()->for($matching)->create(['is_active' => true]);

    $inactivePackage = Package::factory()->for($category)->create([
        'name' => 'Steam Hidden',
        'is_active' => false,
    ]);

    Product::factory()->for($inactivePackage)->create(['is_active' => true]);

    $noProducts = Package::factory()->for($category)->create([
        'name' => 'Steam Empty',
        'is_active' => true,
    ]);

    $response = $this->getJson(route('api.storefront.packages.search', ['q' => 'Steam']));

    $response->assertSuccessful();
    $response->assertJsonCount(1, 'data');
    $response->assertJsonPath('data.0.id', $matching->id);
    $response->assertJsonPath('data.0.name', 'Steam Gift Card');
    $response->assertJsonMissing(['name' => 'Steam Hidden']);
    $response->assertJsonMissing(['name' => 'Steam Empty']);
});

test('package search api validates minimum query length', function () {
    $response = $this->getJson(route('api.storefront.packages.search', ['q' => 'a']));

    $response->assertUnprocessable();
});

test('package search api returns empty list when nothing matches', function () {
    Package::factory()->create([
        'name' => 'Other Package',
        'is_active' => true,
    ]);

    $response = $this->getJson(route('api.storefront.packages.search', ['q' => 'zzznomatch']));

    $response->assertSuccessful();
    $response->assertJsonCount(0, 'data');
});
