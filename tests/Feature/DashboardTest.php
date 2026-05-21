<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

test('guests are redirected to the login page', function () {
    $this->get('/dashboard')->assertRedirect('/login');
});

test('non-admin users receive a not found response', function () {
    $this->actingAs(User::factory()->create());

    $this->get('/dashboard')->assertNotFound();
});

test('admin users can visit the dashboard', function () {
    $role = Role::firstOrCreate(['name' => 'admin']);
    $role->givePermissionTo([
        Permission::firstOrCreate(['name' => 'view_dashboard']),
    ]);
    $admin = User::factory()->create();
    $admin->assignRole($role);

    $this->actingAs($admin);

    $this->get('/dashboard')->assertOk();
});

test('backend users without view_dashboard are forbidden from dashboard', function () {
    $role = Role::firstOrCreate(['name' => 'salesperson']);
    $role->syncPermissions([
        Permission::firstOrCreate(['name' => 'view_referrals']),
    ]);

    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user);

    $this->get('/dashboard')->assertForbidden();
});
