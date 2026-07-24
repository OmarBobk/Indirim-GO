<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Permission::query()->firstOrCreate(['name' => 'view_referrals']);
    Permission::query()->firstOrCreate(['name' => 'manage_referred_users']);
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
});

test('salesperson users index is forbidden without manage_referred_users', function () {
    $user = User::factory()->create();
    $user->givePermissionTo('view_referrals');

    $this->actingAs($user)
        ->get(route('salesperson.users.index'))
        ->assertForbidden();
});

test('salesperson users index lists only users referred by the current user', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $mine = User::factory()->create([
        'name' => 'Referred By Me',
        'referred_by_user_id' => $salesperson->id,
    ]);
    User::factory()->create([
        'name' => 'Someone Else Referral',
        'referred_by_user_id' => User::factory()->create()->id,
    ]);

    $this->actingAs($salesperson)
        ->get(route('salesperson.users.index'))
        ->assertOk()
        ->assertSee('data-test="salesperson-users-page"', false)
        ->assertSee('Referred By Me', false)
        ->assertDontSee('Someone Else Referral', false);
});

test('salesperson cannot open customer detail for a user not under them', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $otherReferrer = User::factory()->create();
    $stranger = User::factory()->create(['referred_by_user_id' => $otherReferrer->id]);

    $this->actingAs($salesperson)
        ->get(route('salesperson.users.show', $stranger))
        ->assertForbidden();
});

test('salesperson can open customer detail for a referred user', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $referred = User::factory()->create([
        'name' => 'My Referred Customer',
        'referred_by_user_id' => $salesperson->id,
    ]);

    $this->actingAs($salesperson)
        ->get(route('salesperson.users.show', $referred))
        ->assertOk()
        ->assertSee('My Referred Customer', false);
});

test('salesperson cannot use admin user URLs even for their referrals', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $referred = User::factory()->create(['referred_by_user_id' => $salesperson->id]);

    $this->actingAs($salesperson)
        ->get(route('admin.users.show', $referred))
        ->assertForbidden();
});

test('salesperson can create a referred user via modals', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $suffix = uniqid('r', false);
    $username = 'refcust_'.$suffix;
    $email = $username.'@example.test';

    Livewire::actingAs($salesperson)
        ->test('pages::backend.salesperson-users.index')
        ->call('referredCreateUser', [
            'name' => 'Referred New User',
            'username' => $username,
            'email' => $email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone' => '',
            'country_code' => '+90',
        ]);

    $created = User::query()->where('email', $email)->firstOrFail();
    expect((int) $created->referred_by_user_id)->toBe((int) $salesperson->id);
    expect($created->hasRole('customer'))->toBeTrue();
});

test('salesperson can update profile fields for a referred user', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $referred = User::factory()->create([
        'name' => 'Old Name',
        'username' => 'oldname'.uniqid('', false),
        'email' => 'old'.uniqid('', false).'@example.test',
        'referred_by_user_id' => $salesperson->id,
    ]);
    $referred->assignRole('customer');

    Livewire::actingAs($salesperson)
        ->test('pages::backend.salesperson-users.index')
        ->call('referredSaveEdit', [
            'id' => $referred->id,
            'name' => 'Updated Name',
            'username' => $referred->username,
            'email' => $referred->email,
            'phone' => $referred->phone ?? '',
            'country_code' => $referred->country_code ?? '+90',
            'password' => '',
            'password_confirmation' => '',
        ]);

    expect($referred->fresh()->name)->toBe('Updated Name');
});

test('salesperson can set password from edit modal for a referred user', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $referred = User::factory()->create([
        'username' => 'pwuser'.uniqid('', false),
        'email' => 'pw'.uniqid('', false).'@example.test',
        'referred_by_user_id' => $salesperson->id,
    ]);
    $referred->assignRole('customer');
    $oldHash = $referred->password;

    Livewire::actingAs($salesperson)
        ->test('pages::backend.salesperson-users.index')
        ->call('referredSaveEdit', [
            'id' => $referred->id,
            'name' => $referred->name,
            'username' => $referred->username,
            'email' => $referred->email,
            'phone' => $referred->phone ?? '',
            'country_code' => $referred->country_code ?? '+90',
            'password' => 'FromEditModal9!',
            'password_confirmation' => 'FromEditModal9!',
        ]);

    expect($referred->fresh()->password)->not->toBe($oldHash);
});

test('salesperson can reset password for a referred user', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $referred = User::factory()->create(['referred_by_user_id' => $salesperson->id]);
    $referred->assignRole('customer');
    $oldHash = $referred->password;

    Livewire::actingAs($salesperson)
        ->test('pages::backend.salesperson-users.index')
        ->call('referredResetPassword', [
            'id' => $referred->id,
            'password' => 'NewPassword456!',
            'password_confirmation' => 'NewPassword456!',
        ]);

    expect($referred->fresh()->password)->not->toBe($oldHash);
});

test('salesperson cannot open edit modal for a user not under them', function () {
    $salesperson = User::factory()->create();
    $salesperson->givePermissionTo(['view_referrals', 'manage_referred_users']);

    $stranger = User::factory()->create(['referred_by_user_id' => User::factory()->create()->id]);

    Livewire::actingAs($salesperson)
        ->test('pages::backend.salesperson-users.index')
        ->call('referredSaveEdit', [
            'id' => $stranger->id,
            'name' => 'X',
            'username' => $stranger->username,
            'email' => $stranger->email,
            'phone' => '',
            'country_code' => '+90',
            'password' => '',
            'password_confirmation' => '',
        ])
        ->assertForbidden();
});
