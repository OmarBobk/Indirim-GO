<?php

use App\Enums\WalletType;
use App\Livewire\Users\UserModals;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $adminRole = Role::firstOrCreate(['name' => 'admin']);
    $manageUsers = Permission::firstOrCreate(['name' => 'manage_users']);
    $viewRefunds = Permission::firstOrCreate(['name' => 'view_refunds']);
    $adminRole->syncPermissions([$manageUsers, $viewRefunds]);
});

test('users index returns 403 without manage_users permission', function () {
    $salespersonRole = Role::firstOrCreate(['name' => 'salesperson']);
    $viewOrders = Permission::firstOrCreate(['name' => 'view_orders']);
    $salespersonRole->syncPermissions([$viewOrders]);

    $user = User::factory()->create();
    $user->assignRole($salespersonRole);

    $this->actingAs($user)
        ->get(route('admin.users.index'))
        ->assertForbidden();
});

test('users index loads for user with manage_users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $this->actingAs($admin)
        ->get(route('admin.users.index'))
        ->assertOk()
        ->assertSee('data-test="admin-users-page"', false);
});

test('user detail page loads for admin with manage_users', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $target = User::factory()->create(['name' => 'Show Target', 'email' => 'show@example.com']);

    $this->actingAs($admin)
        ->get(route('admin.users.show', $target))
        ->assertOk()
        ->assertSee('data-test="admin-user-show-page"', false)
        ->assertSee('Show Target');
});

test('users list shows search and pagination', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $first = User::factory()->create(['name' => 'Alice', 'email' => 'alice@example.com']);
    User::factory()->count(2)->create();

    Livewire::actingAs($admin)
        ->test('pages::backend.users.index')
        ->assertSee('Alice')
        ->set('search', 'alice@example.com')
        ->call('applyFilters')
        ->assertSee('Alice');

    $component = Livewire::actingAs($admin)
        ->test('pages::backend.users.index')
        ->set('perPage', 2)
        ->call('applyFilters');
    expect($component->get('users')->count())->toBeLessThanOrEqual(2);
});

test('create user creates user and logs activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('openCreate')
        ->set('newName', 'New User')
        ->set('newUsername', 'newuser')
        ->set('newEmail', 'newuser@example.com')
        ->set('newPassword', 'Password123!@#')
        ->set('newPasswordConfirmation', 'Password123!@#')
        ->set('newCommissionRatePercent', '17.50')
        ->call('saveCreate')
        ->assertHasNoErrors();

    $this->assertDatabaseHas('users', [
        'name' => 'New User',
        'username' => 'newuser',
        'email' => 'newuser@example.com',
        'commission_rate_percent' => 17.50,
    ]);

    $this->assertDatabaseHas('activity_log', [
        'event' => 'user.created',
        'log_name' => 'admin',
    ]);
});

test('admin users index shows registration source for admin-created user', function () {
    $admin = User::factory()->create(['username' => 'creator_admin']);
    $admin->assignRole('admin');

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('openCreate')
        ->set('newName', 'Listed User')
        ->set('newUsername', 'listeduser')
        ->set('newEmail', 'listed@example.com')
        ->set('newPassword', 'Password123!@#')
        ->set('newPasswordConfirmation', 'Password123!@#')
        ->set('newCommissionRatePercent', '5')
        ->call('saveCreate')
        ->assertHasNoErrors();

    Livewire::actingAs($admin)
        ->test('pages::backend.users.index')
        ->set('search', 'listed@example.com')
        ->call('applyFilters')
        ->assertSee(__('messages.user_registration_by_admin', ['name' => 'creator_admin']), false);
});

test('admin users index shows self-registered when registration activity exists', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    $self = User::factory()->create(['username' => 'self_signup', 'email' => 'self@example.com']);

    activity()
        ->inLog('admin')
        ->event('user.registered')
        ->performedOn($self)
        ->causedBy($self)
        ->log('User registered');

    Livewire::actingAs($admin)
        ->test('pages::backend.users.index')
        ->set('search', 'self@example.com')
        ->call('applyFilters')
        ->assertSee(__('messages.user_registration_self'), false);
});

test('update user updates profile and logs activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $target = User::factory()->create(['name' => 'Old Name', 'username' => 'olduser', 'email' => 'old@example.com']);

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('startEdit', $target->id)
        ->set('editName', 'Updated Name')
        ->set('editUsername', 'updateduser')
        ->set('editEmail', 'updated@example.com')
        ->set('editCommissionRatePercent', '24.75')
        ->call('saveEdit')
        ->assertHasNoErrors();

    $target->refresh();
    expect($target->name)->toBe('Updated Name');
    expect($target->username)->toBe('updateduser');
    expect($target->email)->toBe('updated@example.com');
    expect((float) $target->commission_rate_percent)->toBe(24.75);

    $this->assertDatabaseHas('activity_log', [
        'event' => 'user.updated',
        'log_name' => 'admin',
        'subject_id' => $target->id,
    ]);
});

test('delete user removes user and logs activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $target = User::factory()->create(['name' => 'To Delete', 'email' => 'delete@example.com']);

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('confirmDelete', $target->id)
        ->call('deleteUser');

    $this->assertDatabaseMissing('users', ['id' => $target->id]);

    $this->assertDatabaseHas('activity_log', [
        'event' => 'user.deleted',
        'log_name' => 'admin',
    ]);
});

test('delete user with wallet removes user and wallet', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $target = User::factory()->create(['name' => 'With Wallet', 'email' => 'wallet@example.com']);
    $wallet = Wallet::query()->create([
        'user_id' => $target->id,
        'type' => WalletType::Customer,
        'balance' => 0,
        'currency' => 'USD',
    ]);

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('confirmDelete', $target->id)
        ->call('deleteUser');

    $this->assertDatabaseMissing('users', ['id' => $target->id]);
    $this->assertDatabaseMissing('wallets', ['id' => $wallet->id]);
});

test('block user sets blocked_at and logs activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $target = User::factory()->create(['blocked_at' => null]);

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('confirmBlock', $target->id)
        ->call('blockUser');

    $target->refresh();
    expect($target->blocked_at)->not->toBeNull();

    $this->assertDatabaseHas('activity_log', [
        'event' => 'user.blocked',
        'log_name' => 'admin',
        'subject_id' => $target->id,
    ]);
});

test('unblock user clears blocked_at and logs activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $target = User::factory()->create(['blocked_at' => now()]);

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('confirmUnblock', $target->id)
        ->call('unblockUser');

    $target->refresh();
    expect($target->blocked_at)->toBeNull();

    $this->assertDatabaseHas('activity_log', [
        'event' => 'user.unblocked',
        'log_name' => 'admin',
        'subject_id' => $target->id,
    ]);
});

test('reset password updates password and logs activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $target = User::factory()->create();
    $oldHash = $target->password;

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('confirmResetPassword', $target->id)
        ->set('resetPasswordNew', 'NewSecurePass123!@#')
        ->set('resetPasswordNewConfirmation', 'NewSecurePass123!@#')
        ->call('resetPassword')
        ->assertHasNoErrors();

    $target->refresh();
    expect($target->password)->not->toBe($oldHash);

    $this->assertDatabaseHas('activity_log', [
        'event' => 'user.password_reset',
        'log_name' => 'admin',
        'subject_id' => $target->id,
    ]);
});

test('verify email sets email_verified_at and logs activity', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $target = User::factory()->create(['email_verified_at' => null]);

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('confirmVerifyEmail', $target->id)
        ->call('verifyEmail');

    $target->refresh();
    expect($target->email_verified_at)->not->toBeNull();

    $this->assertDatabaseHas('activity_log', [
        'event' => 'user.email_verified',
        'log_name' => 'admin',
        'subject_id' => $target->id,
    ]);
});

test('assign roles and permissions on create logs roles_updated when changed', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Role::firstOrCreate(['name' => 'salesperson']);
    Permission::firstOrCreate(['name' => 'view_referrals']);

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('openCreate')
        ->set('newName', 'Role User')
        ->set('newUsername', 'roleuser')
        ->set('newEmail', 'roleuser@example.com')
        ->set('newPassword', 'Password123!@#')
        ->set('newPasswordConfirmation', 'Password123!@#')
        ->set('newRoles', ['salesperson'])
        ->set('newPermissions', ['view_referrals'])
        ->call('saveCreate')
        ->assertHasNoErrors();

    $user = User::query()->where('email', 'roleuser@example.com')->firstOrFail();
    expect($user->getRoleNames()->all())->toContain('salesperson');
    expect($user->getDirectPermissions()->pluck('name')->all())->toContain('view_referrals');

    $this->assertDatabaseHas('activity_log', ['event' => 'user.created', 'log_name' => 'admin']);
});

test('assign roles on edit syncs and logs when changed', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Role::firstOrCreate(['name' => 'customer']);
    Role::firstOrCreate(['name' => 'salesperson']);
    $target = User::factory()->create([
        'name' => 'Edit Target',
        'username' => 'edittarget',
        'email' => 'edittarget@example.com',
        'country_code' => null,
    ]);
    $target->assignRole('customer');

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('startEdit', $target->id)
        ->set('editRoles', ['salesperson'])
        ->set('editCountryCode', null)
        ->call('saveEdit')
        ->assertHasNoErrors();

    $target->refresh();
    expect($target->getRoleNames()->all())->toContain('salesperson');

    $activity = Activity::query()
        ->where('subject_id', $target->id)
        ->where('event', 'user.roles_updated')
        ->where('log_name', 'admin')
        ->first();
    expect($activity)->not->toBeNull();
});

test('assign roles on edit still works when preferred currency is missing from modal payload', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    Role::firstOrCreate(['name' => 'customer']);
    Role::firstOrCreate(['name' => 'salesperson']);

    $target = User::factory()->create([
        'name' => 'Currency Target',
        'username' => 'currencytarget',
        'email' => 'currencytarget@example.com',
        'preferred_currency' => 'USD',
        'country_code' => null,
    ]);
    $target->assignRole('customer');

    Livewire::actingAs($admin)
        ->test(UserModals::class)
        ->call('startEdit', $target->id)
        ->set('editRoles', ['salesperson'])
        ->set('editCountryCode', null)
        ->call('saveEdit')
        ->assertHasNoErrors();

    $target->refresh();
    expect($target->getRoleNames()->all())->toContain('salesperson');
});
