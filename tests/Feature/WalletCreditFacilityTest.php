<?php

declare(strict_types=1);

use App\Actions\Wallets\UpdateCreditFacility;
use App\Enums\CreditFacilityStatus;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function grantManageWalletCredit(User $user): User
{
    $permission = Permission::firstOrCreate(['name' => 'manage_wallet_credit']);
    $role = Role::firstOrCreate(['name' => 'admin']);
    $role->givePermissionTo($permission);
    $user->assignRole($role);

    return $user;
}

function makeAdminWithManageWalletCredit(): User
{
    return grantManageWalletCredit(User::factory()->create());
}

// ─── Authorization ───────────────────────────────────────────────────────────

test('admin with manage_wallet_credit can open credit facility page', function () {
    $admin = makeAdminWithManageWalletCredit();

    $this->actingAs($admin)
        ->get(route('credit-facility'))
        ->assertOk()
        ->assertSee(__('messages.credit_facility'));
});

test('authenticated user without manage_wallet_credit cannot open credit facility page', function () {
    $permission = Permission::firstOrCreate(['name' => 'manage_topups']);
    $role = Role::firstOrCreate(['name' => 'ops']);
    $role->givePermissionTo($permission);

    $user = User::factory()->create();
    $user->assignRole($role);

    $this->actingAs($user)
        ->get(route('credit-facility'))
        ->assertForbidden();
});

test('guest is redirected away from credit facility page', function () {
    $this->get(route('credit-facility'))
        ->assertRedirect();
});

test('UpdateCreditFacility denies actors without manage_wallet_credit', function () {
    $actor = User::factory()->create();
    $target = User::factory()->create();

    expect(fn () => app(UpdateCreditFacility::class)->handle(
        actor: $actor,
        targetUser: $target,
        input: [
            'credit_enabled' => true,
            'credit_limit' => '100.00',
            'payment_terms_days' => 30,
            'credit_status' => CreditFacilityStatus::Active->value,
        ],
    ))->toThrow(AuthorizationException::class);
});

// ─── Happy path ──────────────────────────────────────────────────────────────

test('admin can enable credit facility with limit terms and status', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create(['name' => 'Omar']);
    $wallet = Wallet::forUser($customer);

    $updated = app(UpdateCreditFacility::class)->handle(
        actor: $admin,
        targetUser: $customer,
        input: [
            'credit_enabled' => true,
            'credit_limit' => '250.00',
            'payment_terms_days' => 45,
            'credit_status' => CreditFacilityStatus::Active->value,
        ],
    );

    expect($updated->credit_enabled)->toBeTrue()
        ->and(bcadd((string) $updated->credit_limit, '0', 2))->toBe('250.00')
        ->and($updated->payment_terms_days)->toBe(45)
        ->and($updated->credit_status)->toBe(CreditFacilityStatus::Active)
        ->and($updated->effectiveCreditLimit())->toBe('250.00');

    $wallet->refresh();
    expect($wallet->credit_enabled)->toBeTrue()
        ->and($wallet->payment_terms_days)->toBe(45);
});

test('admin can suspend credit facility without clearing limit', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update([
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $updated = app(UpdateCreditFacility::class)->handle(
        actor: $admin,
        targetUser: $customer,
        input: [
            'credit_enabled' => true,
            'credit_limit' => '100.00',
            'payment_terms_days' => 30,
            'credit_status' => CreditFacilityStatus::Suspended->value,
        ],
    );

    expect($updated->credit_enabled)->toBeTrue()
        ->and(bcadd((string) $updated->credit_limit, '0', 2))->toBe('100.00')
        ->and($updated->credit_status)->toBe(CreditFacilityStatus::Suspended)
        ->and($updated->effectiveCreditLimit())->toBe('0.00');
});

test('rejects credit limit below outstanding debt', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update([
        'balance' => '-80.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    expect(fn () => app(UpdateCreditFacility::class)->handle(
        actor: $admin,
        targetUser: $customer,
        input: [
            'credit_enabled' => true,
            'credit_limit' => '50.00',
            'payment_terms_days' => 30,
            'credit_status' => CreditFacilityStatus::Active->value,
        ],
    ))->toThrow(ValidationException::class);

    try {
        app(UpdateCreditFacility::class)->handle(
            actor: $admin,
            targetUser: $customer,
            input: [
                'credit_enabled' => true,
                'credit_limit' => '50.00',
                'payment_terms_days' => 30,
                'credit_status' => CreditFacilityStatus::Active->value,
            ],
        );
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('credit_limit')
            ->and($e->errors()['credit_limit'][0])->toContain('80.00');
    }

    $wallet->refresh();
    expect(bcadd((string) $wallet->credit_limit, '0', 2))->toBe('100.00');
});

test('records structured activity log properties for credit facility update', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create(['name' => 'Sara']);
    $wallet = Wallet::forUser($customer);
    $wallet->update([
        'credit_enabled' => false,
        'credit_limit' => '0.00',
        'payment_terms_days' => null,
        'credit_status' => null,
    ]);

    app(UpdateCreditFacility::class)->handle(
        actor: $admin,
        targetUser: $customer,
        input: [
            'credit_enabled' => true,
            'credit_limit' => '200.00',
            'payment_terms_days' => 60,
            'credit_status' => CreditFacilityStatus::Active->value,
        ],
    );

    $activity = Activity::query()
        ->where('event', 'wallet.credit_facility.updated')
        ->latest('id')
        ->first();

    expect($activity)->not->toBeNull()
        ->and($activity->description)->toContain('Sara')
        ->and($activity->properties->get('previous_limit'))->toBe('0.00')
        ->and($activity->properties->get('new_limit'))->toBe('200.00')
        ->and($activity->properties->get('previous_terms'))->toBeNull()
        ->and($activity->properties->get('new_terms'))->toBe(60)
        ->and($activity->properties->get('previous_enabled'))->toBeFalse()
        ->and($activity->properties->get('new_enabled'))->toBeTrue()
        ->and($activity->properties->get('previous_status'))->toBeNull()
        ->and($activity->properties->get('new_status'))->toBe('active');
});

test('platform wallets never receive an effective credit facility', function () {
    $platform = Wallet::forPlatform();
    $platform->update([
        'credit_enabled' => true,
        'credit_limit' => '500.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    expect($platform->type)->toBe(WalletType::Platform)
        ->and($platform->effectiveCreditLimit())->toBe('0.00')
        ->and($platform->availableCredit())->toBe(
            bccomp((string) $platform->balance, '0', 2) === -1
                ? '0.00'
                : bcadd((string) $platform->balance, '0', 2)
        );
});

// ─── Livewire review / save path ─────────────────────────────────────────────

test('livewire review and confirm saves credit facility', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create(['name' => 'Lina']);
    Wallet::forUser($customer);

    Livewire::actingAs($admin)
        ->test('pages::backend.credit-facility.index')
        ->call('selectUser', $customer->id)
        ->set('creditEnabled', true)
        ->set('creditLimit', '150.00')
        ->set('paymentTermsDays', 30)
        ->set('creditStatus', CreditFacilityStatus::Active->value)
        ->call('reviewFacility')
        ->assertSet('showReviewSummary', true)
        ->assertSee(__('messages.previous_credit_limit'))
        ->assertSee(__('messages.new_credit_limit'))
        ->assertSee(__('messages.outstanding_debt'))
        ->assertSee(__('messages.available_credit_after'))
        ->set('confirmAcknowledged', true)
        ->call('confirmFacility')
        ->assertSet('showReviewSummary', false)
        ->assertHasNoErrors();

    $wallet = Wallet::forUser($customer);
    expect($wallet->credit_enabled)->toBeTrue()
        ->and(bcadd((string) $wallet->credit_limit, '0', 2))->toBe('150.00')
        ->and($wallet->payment_terms_days)->toBe(30)
        ->and($wallet->credit_status)->toBe(CreditFacilityStatus::Active);
});

test('livewire rejects limit below outstanding debt on review', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update([
        'balance' => '-80.00',
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::backend.credit-facility.index')
        ->call('selectUser', $customer->id)
        ->set('creditEnabled', true)
        ->set('creditLimit', '40.00')
        ->set('paymentTermsDays', 30)
        ->set('creditStatus', CreditFacilityStatus::Active->value)
        ->call('reviewFacility')
        ->assertHasErrors(['creditLimit']);
});

test('requires payment terms when enabling credit facility', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create();

    expect(fn () => app(UpdateCreditFacility::class)->handle(
        actor: $admin,
        targetUser: $customer,
        input: [
            'credit_enabled' => true,
            'credit_limit' => '100.00',
            'payment_terms_days' => null,
            'credit_status' => CreditFacilityStatus::Active->value,
        ],
    ))->toThrow(ValidationException::class);
});

test('disabling credit facility clears status to null', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update([
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    $updated = app(UpdateCreditFacility::class)->handle(
        actor: $admin,
        targetUser: $customer,
        input: [
            'credit_enabled' => false,
            'credit_limit' => '100.00',
            'payment_terms_days' => null,
            'credit_status' => null,
        ],
    );

    expect($updated->credit_enabled)->toBeFalse()
        ->and($updated->credit_status)->toBeNull()
        ->and($updated->effectiveCreditLimit())->toBe('0.00');
});

test('rejects disabled facility with operational status', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create();

    expect(fn () => app(UpdateCreditFacility::class)->handle(
        actor: $admin,
        targetUser: $customer,
        input: [
            'credit_enabled' => false,
            'credit_limit' => '0.00',
            'payment_terms_days' => null,
            'credit_status' => CreditFacilityStatus::Active->value,
        ],
    ))->toThrow(ValidationException::class);

    try {
        app(UpdateCreditFacility::class)->handle(
            actor: $admin,
            targetUser: $customer,
            input: [
                'credit_enabled' => false,
                'credit_limit' => '0.00',
                'payment_terms_days' => null,
                'credit_status' => CreditFacilityStatus::Suspended->value,
            ],
        );
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('credit_status');
    }
});

test('enabling credit facility requires Active or Suspended status', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create();

    expect(fn () => app(UpdateCreditFacility::class)->handle(
        actor: $admin,
        targetUser: $customer,
        input: [
            'credit_enabled' => true,
            'credit_limit' => '100.00',
            'payment_terms_days' => 30,
            'credit_status' => null,
        ],
    ))->toThrow(ValidationException::class);

    $suspended = app(UpdateCreditFacility::class)->handle(
        actor: $admin,
        targetUser: $customer,
        input: [
            'credit_enabled' => true,
            'credit_limit' => '100.00',
            'payment_terms_days' => 30,
            'credit_status' => CreditFacilityStatus::Suspended->value,
        ],
    );

    expect($suspended->credit_enabled)->toBeTrue()
        ->and($suspended->credit_status)->toBe(CreditFacilityStatus::Suspended)
        ->and($suspended->effectiveCreditLimit())->toBe('0.00');
});

test('livewire clears status when disabling facility', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create();
    $wallet = Wallet::forUser($customer);
    $wallet->update([
        'credit_enabled' => true,
        'credit_limit' => '80.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::backend.credit-facility.index')
        ->call('selectUser', $customer->id)
        ->assertSet('creditEnabled', true)
        ->assertSet('creditStatus', CreditFacilityStatus::Active->value)
        ->set('creditEnabled', false)
        ->assertSet('creditStatus', null)
        ->call('reviewFacility')
        ->assertSet('showReviewSummary', true)
        ->set('confirmAcknowledged', true)
        ->call('confirmFacility')
        ->assertHasNoErrors();

    $wallet->refresh();
    expect($wallet->credit_enabled)->toBeFalse()
        ->and($wallet->credit_status)->toBeNull();
});

// ─── Ops list / filters ──────────────────────────────────────────────────────

test('credit facility page shows granted facilities in default ops list', function () {
    $admin = makeAdminWithManageWalletCredit();
    $granted = User::factory()->create(['name' => 'Granted Customer']);
    $plain = User::factory()->create(['name' => 'Plain Customer']);

    $grantedWallet = Wallet::forUser($granted);
    $grantedWallet->update([
        'credit_enabled' => true,
        'credit_limit' => '200.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    Wallet::forUser($plain);

    Livewire::actingAs($admin)
        ->test('pages::backend.credit-facility.index')
        ->assertSet('opsFilter', 'relevant')
        ->assertSee('Granted Customer')
        ->assertDontSee('Plain Customer')
        ->assertSee(__('messages.credit_facility_ops_list'));
});

test('credit facility ops list includes overdrawn wallets without a facility', function () {
    $admin = makeAdminWithManageWalletCredit();
    $overdrawn = User::factory()->create(['name' => 'Overdrawn Customer']);
    $wallet = Wallet::forUser($overdrawn);
    $wallet->update([
        'balance' => '-25.00',
        'credit_enabled' => false,
        'credit_limit' => '0.00',
        'credit_status' => null,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::backend.credit-facility.index')
        ->assertSee('Overdrawn Customer')
        ->assertSee(__('messages.outstanding_debt'));
});

test('credit facility filter shows only active facilities', function () {
    $admin = makeAdminWithManageWalletCredit();
    $active = User::factory()->create(['name' => 'Active Facility']);
    $suspended = User::factory()->create(['name' => 'Suspended Facility']);

    Wallet::forUser($active)->update([
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    Wallet::forUser($suspended)->update([
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Suspended,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::backend.credit-facility.index')
        ->set('opsFilter', 'active')
        ->assertSee('Active Facility')
        ->assertDontSee('Suspended Facility');
});

test('credit facility filter shows only suspended facilities', function () {
    $admin = makeAdminWithManageWalletCredit();
    $active = User::factory()->create(['name' => 'Active Facility']);
    $suspended = User::factory()->create(['name' => 'Suspended Facility']);

    Wallet::forUser($active)->update([
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    Wallet::forUser($suspended)->update([
        'credit_enabled' => true,
        'credit_limit' => '100.00',
        'payment_terms_days' => 30,
        'credit_status' => CreditFacilityStatus::Suspended,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::backend.credit-facility.index')
        ->set('opsFilter', 'suspended')
        ->assertSee('Suspended Facility')
        ->assertDontSee('Active Facility');
});

test('selecting an ops list row loads the facility edit sections', function () {
    $admin = makeAdminWithManageWalletCredit();
    $customer = User::factory()->create(['name' => 'Row Select Customer']);
    $wallet = Wallet::forUser($customer);
    $wallet->update([
        'credit_enabled' => true,
        'credit_limit' => '175.00',
        'payment_terms_days' => 45,
        'credit_status' => CreditFacilityStatus::Active,
    ]);

    Livewire::actingAs($admin)
        ->test('pages::backend.credit-facility.index')
        ->call('selectUser', $customer->id)
        ->assertSet('selectedUserId', $customer->id)
        ->assertSet('creditEnabled', true)
        ->assertSet('creditLimit', '175.00')
        ->assertSet('paymentTermsDays', 45)
        ->assertSet('creditStatus', CreditFacilityStatus::Active->value)
        ->assertSee(__('messages.credit_facility_section_wallet'))
        ->assertSee(__('messages.credit_facility_section_facility'))
        ->assertSee('Row Select Customer');
});

test('empty relevant filter shows grant guidance', function () {
    $admin = makeAdminWithManageWalletCredit();
    $plain = User::factory()->create(['name' => 'No Facility Yet']);
    Wallet::forUser($plain);

    Livewire::actingAs($admin)
        ->test('pages::backend.credit-facility.index')
        ->assertSee(__('messages.credit_facility_empty_relevant'))
        ->assertSee(__('messages.credit_facility_empty_relevant_hint'));
});
