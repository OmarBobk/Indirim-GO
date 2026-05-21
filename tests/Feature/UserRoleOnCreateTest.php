<?php

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Users\CreateUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'salesperson', 'guard_name' => 'web']);
    Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'manage_users', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);
    Permission::firstOrCreate(['name' => 'manage_referred_users', 'guard_name' => 'web']);
});

test('fortify registration assigns the customer role', function () {
    $user = app(CreateNewUser::class)->create([
        'name' => 'Registered User',
        'username' => 'registereduser',
        'email' => 'registered@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'preferred_currency' => 'USD',
    ]);

    expect($user->hasRole('customer'))->toBeTrue();
    expect($user->getRoleNames()->all())->toBe(['customer']);
});

test('admin create user without roles assigns the customer role', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $created = app(CreateUser::class)->handle([
        'name' => 'Admin Created User',
        'username' => 'admincreated',
        'email' => 'admincreated@example.com',
        'password' => 'Password123!@#',
        'password_confirmation' => 'Password123!@#',
    ], $admin->id);

    expect($created->hasRole('customer'))->toBeTrue();
    expect($created->getRoleNames()->all())->toBe(['customer']);
});

test('admin create user with explicit roles keeps only those roles', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $created = app(CreateUser::class)->handle([
        'name' => 'Salesperson User',
        'username' => 'salespersonuser',
        'email' => 'salespersonuser@example.com',
        'password' => 'Password123!@#',
        'password_confirmation' => 'Password123!@#',
        'roles' => ['salesperson'],
        'permissions' => ['view_referrals'],
    ], $admin->id);

    expect($created->hasRole('salesperson'))->toBeTrue();
    expect($created->hasRole('customer'))->toBeFalse();
    expect($created->getRoleNames()->all())->toBe(['salesperson']);
    expect($created->getDirectPermissions()->pluck('name')->all())->toContain('view_referrals');
});

test('salesperson referred create keeps customer role only', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $created = app(CreateUser::class)->handle([
        'name' => 'Referred User',
        'username' => 'referreduser',
        'email' => 'referreduser@example.com',
        'password' => 'Password123!@#',
        'password_confirmation' => 'Password123!@#',
    ], $salesperson->id, $salesperson->id);

    expect($created->hasRole('customer'))->toBeTrue();
    expect($created->getRoleNames()->all())->toBe(['customer']);
});
