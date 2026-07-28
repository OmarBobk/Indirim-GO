<?php

use App\Actions\Fortify\CreateNewUser;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
});

test('login applies accept language when account locale is not manually locked', function () {
    $user = User::factory()->create([
        'locale' => 'en',
        'locale_manually_set' => false,
    ]);

    $this->withHeader('Accept-Language', 'ar')
        ->post(route('login.store'), [
            'username' => $user->username,
            'password' => '123',
        ])
        ->assertSessionHasNoErrors();

    expect(session('locale'))->toBe('ar');
    expect($user->fresh()->locale)->toBe('ar');
    expect($user->fresh()->locale_manually_set)->toBeFalse();
});

test('login respects manually locked locale over accept language', function () {
    $user = User::factory()->create([
        'locale' => 'en',
        'locale_manually_set' => true,
    ]);

    $this->withHeader('Accept-Language', 'ar')
        ->post(route('login.store'), [
            'username' => $user->username,
            'password' => '123',
        ])
        ->assertSessionHasNoErrors();

    expect(session('locale'))->toBe('en');
    expect($user->fresh()->locale)->toBe('en');
});

test('login promotes guest session locale to account and locks preference', function () {
    $user = User::factory()->create([
        'locale' => 'en',
        'locale_manually_set' => false,
    ]);

    $this->withSession(['locale' => 'ar'])
        ->withHeader('Accept-Language', 'en')
        ->post(route('login.store'), [
            'username' => $user->username,
            'password' => '123',
        ])
        ->assertSessionHasNoErrors();

    expect(session('locale'))->toBe('ar');
    expect($user->fresh()->locale)->toBe('ar');
    expect($user->fresh()->locale_manually_set)->toBeTrue();
});

test('new user creation applies locale from accept language', function () {
    $this->withHeaders(['Accept-Language' => 'ar'])
        ->get(route('home'))
        ->assertOk();

    $user = app(CreateNewUser::class)->create([
        'name' => 'John Doe',
        'username' => 'loginlocale_john',
        'email' => 'loginlocale_john@example.com',
        'password' => 'password',
        'password_confirmation' => 'password',
        'preferred_currency' => 'USD',
    ]);

    expect($user->locale)->toBe('ar');
    expect($user->locale_manually_set)->toBeFalse();
});
