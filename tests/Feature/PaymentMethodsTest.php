<?php

use App\Models\PaymentMethod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
});

test('admin can add and edit payment methods on website settings', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Livewire::actingAs($admin)
        ->test('pages::backend.website-settings.index')
        ->call('startCreatePaymentMethod')
        ->set('paymentMethodName', 'Papara')
        ->set('paymentMethodAccountText', '1234567890')
        ->set('paymentMethodSortOrder', 2)
        ->set('paymentMethodIsActive', true)
        ->call('savePaymentMethod')
        ->assertDispatched('payment-methods-saved');

    $method = PaymentMethod::query()->where('name', 'Papara')->first();
    expect($method)->not->toBeNull();
    expect($method->account_text)->toBe('1234567890');

    Livewire::actingAs($admin)
        ->test('pages::backend.website-settings.index')
        ->call('editPaymentMethod', $method->id)
        ->set('paymentMethodName', 'Papara Wallet')
        ->call('savePaymentMethod');

    expect($method->fresh()->name)->toBe('Papara Wallet');
});

test('admin can upload payment method image', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');

    Livewire::actingAs($admin)
        ->test('pages::backend.website-settings.index')
        ->call('startCreatePaymentMethod')
        ->set('paymentMethodName', 'With Logo')
        ->set('paymentMethodAccountText', 'ACC-1')
        ->set('paymentMethodImageFile', UploadedFile::fake()->image('logo.png'))
        ->call('savePaymentMethod');

    $method = PaymentMethod::query()->where('name', 'With Logo')->first();
    expect($method?->image)->not->toBeNull();
    expect(file_exists(public_path((string) $method->image)))->toBeTrue();
});

test('wallet page shows active payment methods only', function () {
    PaymentMethod::query()->delete();
    PaymentMethod::factory()->shamCash()->create(['is_active' => true]);
    PaymentMethod::factory()->eftTransfer()->inactive()->create();

    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('wallet'))
        ->assertOk()
        ->assertSee('data-test="wallet-payment-methods"', false)
        ->assertSee('Sham Cash')
        ->assertDontSee('EFT Transfer');
});

test('migration seeds legacy topup methods', function () {
    expect(PaymentMethod::query()->where('name', 'Sham Cash')->exists())->toBeTrue();
    expect(PaymentMethod::query()->where('name', 'EFT Transfer')->exists())->toBeTrue();
});
