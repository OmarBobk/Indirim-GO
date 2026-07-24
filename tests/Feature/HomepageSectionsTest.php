<?php

use App\Models\Category;
use App\Models\Package;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders homepage sliders', function () {
    $category = Category::factory()->create([
        'name' => 'Console Cards',
        'is_active' => true,
        'image' => null,
        'parent_id' => null,
    ]);

    Package::factory()->create([
        'name' => 'Test Package',
        'category_id' => $category->id,
        'is_active' => true,
        'image' => null,
    ]);

    $this->get('/')
        ->assertOk()
        ->assertSeeLivewire('main.circular-slider')
        ->assertSeeLivewire('main.promotional-sliders')
        ->assertSee('group-hover:border-accent')
        ->assertSee('data-test="circular-slider-item"', false)
        ->assertSee('onerror="this.onerror=null; this.src=', false)
        ->assertSee('data-test="homepage-categories-grid"', false)
        ->assertSee('grid grid-cols-2', false)
        ->assertSee('data-test="homepage-category-card"', false)
        ->assertSee('Console Cards')
        ->assertSee(route('categories.show', ['category' => $category->slug]), false)
        ->assertSee('data-test="category-package-pills"', false)
        ->assertSee('Test Package', false);
});
