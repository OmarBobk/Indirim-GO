<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Fortify\Features;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    RateLimiter::clear('registration:requests:minute:127.0.0.1');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function registrationPayload(array $overrides = []): array
{
    return [
        'name' => 'John Doe',
        'username' => 'johndoe',
        'email' => 'john@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'preferred_currency' => 'USD',
        'website' => '',
        ...$overrides,
    ];
}

function enableTurnstileForTest(): void
{
    config([
        'services.turnstile.enabled' => true,
        'services.turnstile.site_key' => 'test-site-key',
        'services.turnstile.secret_key' => 'test-secret-key',
    ]);
}

function fakeTurnstileSuccess(): void
{
    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => true]),
    ]);
}

test('registration feature is enabled and routes are available', function () {
    expect(Features::enabled(Features::registration()))->toBeTrue()
        ->and(route('register'))->toContain('/register')
        ->and(route('register.store'))->toContain('/register');
});

test('registration screen can be rendered', function () {
    $this->get(route('register'))
        ->assertOk()
        ->assertSee('name="website"', false);
});

test('registration screen shows turnstile widget when enabled', function () {
    enableTurnstileForTest();

    $this->get(route('register'))
        ->assertOk()
        ->assertSee('cf-turnstile', false)
        ->assertSee('test-site-key', false);
});

test('new users can register when turnstile is disabled', function () {
    config(['services.turnstile.enabled' => false]);

    $response = $this->post(route('register.store'), registrationPayload());

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticated();
    expect(User::query()->where('email', 'john@example.com')->exists())->toBeTrue();
});

test('new users can register with a valid turnstile token', function () {
    enableTurnstileForTest();
    fakeTurnstileSuccess();

    $response = $this->post(route('register.store'), registrationPayload([
        'cf-turnstile-response' => 'valid-token',
    ]));

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticated();
    Http::assertSentCount(1);
});

test('registration fails with an invalid turnstile token', function () {
    enableTurnstileForTest();

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false]),
    ]);

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload([
        'cf-turnstile-response' => 'invalid-token',
    ]));

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('cf-turnstile-response');

    $this->assertGuest();
    expect(User::query()->where('email', 'john@example.com')->exists())->toBeFalse();
});

test('registration fails when turnstile token is missing', function () {
    enableTurnstileForTest();
    Http::fake();

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload());

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('cf-turnstile-response');

    $this->assertGuest();
    Http::assertNothingSent();
    expect(User::query()->where('email', 'john@example.com')->exists())->toBeFalse();
});

test('registration fails when turnstile provider times out', function () {
    enableTurnstileForTest();

    Http::fake([
        'challenges.cloudflare.com/*' => Http::failedConnection(),
    ]);

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload([
        'cf-turnstile-response' => 'any-token',
    ]));

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('cf-turnstile-response');

    $this->assertGuest();
    expect(User::query()->where('email', 'john@example.com')->exists())->toBeFalse();
});

test('registration fails when turnstile provider returns a server error', function () {
    enableTurnstileForTest();

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response('unavailable', 503),
    ]);

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload([
        'cf-turnstile-response' => 'any-token',
    ]));

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('cf-turnstile-response');

    $this->assertGuest();
    expect(User::query()->where('email', 'john@example.com')->exists())->toBeFalse();
});

test('registration rejects honeypot submissions without creating a user', function () {
    config(['services.turnstile.enabled' => false]);

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload([
        'website' => 'https://spam.example',
    ]));

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('website');

    $this->assertGuest();
    expect(User::query()->where('email', 'john@example.com')->exists())->toBeFalse();
});

test('registration is blocked after too many requests per minute from the same ip', function () {
    config([
        'services.turnstile.enabled' => false,
        'security.registration.requests_per_minute_per_ip' => 2,
    ]);

    $limiter = app(\App\Domain\Security\Services\RegistrationRateLimiter::class);
    $limiter->hitAttempt('127.0.0.1', 'seed-a@example.com');
    $limiter->hitAttempt('127.0.0.1', 'seed-b@example.com');

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload([
        'username' => 'userthree',
        'email' => 'three@example.com',
    ]));

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'three@example.com')->exists())->toBeFalse();
});

test('registration is blocked after hourly successful registrations from the same ip', function () {
    config([
        'services.turnstile.enabled' => false,
        'security.registration.successful_per_hour_per_ip' => 2,
        'security.registration.requests_per_minute_per_ip' => 100,
    ]);

    $limiter = app(\App\Domain\Security\Services\RegistrationRateLimiter::class);
    $limiter->recordSuccess('127.0.0.1');
    $limiter->recordSuccess('127.0.0.1');

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload([
        'username' => 'successthree',
        'email' => 'success3@example.com',
    ]));

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'success3@example.com')->exists())->toBeFalse();
});

test('registration is blocked after daily successful registrations from the same ip', function () {
    config([
        'services.turnstile.enabled' => false,
        'security.registration.successful_per_day_per_ip' => 2,
        'security.registration.successful_per_hour_per_ip' => 100,
        'security.registration.requests_per_minute_per_ip' => 100,
    ]);

    $limiter = app(\App\Domain\Security\Services\RegistrationRateLimiter::class);
    $limiter->recordSuccess('127.0.0.1');
    $limiter->recordSuccess('127.0.0.1');

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload([
        'username' => 'daythree',
        'email' => 'day3@example.com',
    ]));

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'day3@example.com')->exists())->toBeFalse();
});

test('successful registration records toward the ip success budget', function () {
    config([
        'services.turnstile.enabled' => false,
        'security.registration.successful_per_hour_per_ip' => 1,
        'security.registration.requests_per_minute_per_ip' => 100,
    ]);

    $this->post(route('register.store'), registrationPayload([
        'username' => 'firstuser',
        'email' => 'first@example.com',
    ]))->assertRedirect(route('home', absolute: false));

    $this->post(route('logout'));
    $this->assertGuest();

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload([
        'username' => 'seconduser',
        'email' => 'second@example.com',
    ]));

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');
});

test('registration is blocked after too many attempts for the same email', function () {
    enableTurnstileForTest();
    config([
        'security.registration.attempts_per_hour_per_email' => 2,
        'security.registration.requests_per_minute_per_ip' => 100,
    ]);

    Http::fake([
        'challenges.cloudflare.com/*' => Http::response(['success' => false]),
    ]);

    $payload = registrationPayload([
        'cf-turnstile-response' => 'bad-token',
    ]);

    $this->from(route('register'))->post(route('register.store'), $payload);
    $this->from(route('register'))->post(route('register.store'), $payload);

    $response = $this->from(route('register'))->post(route('register.store'), $payload);

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');

    expect(User::query()->where('email', 'john@example.com')->exists())->toBeFalse();
});

test('duplicate registration with the same email is rejected', function () {
    config(['services.turnstile.enabled' => false]);

    $this->post(route('register.store'), registrationPayload())
        ->assertRedirect(route('home', absolute: false));

    $this->post(route('logout'));

    $response = $this->from(route('register'))->post(route('register.store'), registrationPayload([
        'username' => 'differentuser',
    ]));

    $response
        ->assertRedirect(route('register'))
        ->assertSessionHasErrors('email');
});

test('create new user action remains usable without registration security', function () {
    config(['services.turnstile.enabled' => true]);

    $user = app(\App\Actions\Fortify\CreateNewUser::class)->create([
        'name' => 'Direct User',
        'username' => 'directuser',
        'email' => 'direct@example.com',
        'password' => 'Password123!',
        'password_confirmation' => 'Password123!',
        'preferred_currency' => 'USD',
    ]);

    expect($user->email)->toBe('direct@example.com')
        ->and($user->hasRole('customer'))->toBeTrue();
});
