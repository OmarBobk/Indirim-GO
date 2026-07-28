<?php

use App\Models\Category;
use App\Models\Package;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function categoryProductsAdminUser(): User
{
    $role = Role::firstOrCreate(['name' => 'admin']);
    $role->syncPermissions([
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage_sections']),
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'manage_products']),
    ]);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

test('category validation uses arabic attribute names', function () {
    $admin = categoryProductsAdminUser();

    app()->setLocale('ar');

    Livewire::actingAs($admin)
        ->test('pages::backend.categories.index')
        ->set('newName', '')
        ->set('newOrder', 1)
        ->call('save')
        ->assertHasErrors(['newName' => 'required'])
        ->assertSee('حقل الاسم مطلوب.');
});

test('product validation uses arabic attribute names', function () {
    $admin = categoryProductsAdminUser();
    $category = Category::factory()->create();
    $package = Package::factory()->for($category)->create();

    app()->setLocale('ar');

    Livewire::actingAs($admin)
        ->test('pages::backend.products.index')
        ->set('productPackageId', $package->id)
        ->set('productOrder', 1)
        ->set('productEntryPrice', 10)
        ->set('productName', '')
        ->call('saveProduct')
        ->assertHasErrors(['productName' => 'required'])
        ->assertSee('حقل الاسم مطلوب.');
});
