<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;

/**
 * @param  list<string>  $recoveryCodes
 */
function webAuthEnableTwoFactor(User $user, array $recoveryCodes = ['web-recovery-code']): void
{
    $user->forceFill([
        'two_factor_secret' => encrypt('web-test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ])->save();
}

test('normal web login preserves remember me intended redirect audit and logout', function () {
    $user = User::factory()->create(['username' => 'browser-customer']);

    $response = $this
        ->withSession(['url.intended' => '/wallet'])
        ->post(route('login.store'), [
            'username' => 'BROWSER-CUSTOMER',
            'password' => '123',
            'remember' => true,
        ]);

    $response
        ->assertSessionHasNoErrors()
        ->assertRedirect('/wallet')
        ->assertCookie(Auth::guard('web')->getRecallerName());

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->last_login_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('event', 'user.login')
            ->where('subject_id', $user->id)
            ->count())->toBe(1);

    $this->post(route('logout'))
        ->assertRedirect(route('home'));
    $this->assertGuest();
});

test('web authenticator challenge preserves remember me redirect and login audit', function () {
    $user = User::factory()->create(['username' => 'web-two-factor']);
    webAuthEnableTwoFactor($user);

    $this
        ->withSession(['url.intended' => '/profile'])
        ->post(route('login.store'), [
            'username' => $user->username,
            'password' => '123',
            'remember' => true,
        ])
        ->assertRedirect(route('two-factor.login'));
    $this->assertGuest();

    $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('verify')->once()->andReturnTrue();
    });

    $this->post(route('two-factor.login.store'), [
        'code' => '123456',
    ])
        ->assertRedirect('/profile')
        ->assertCookie(Auth::guard('web')->getRecallerName());

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->last_login_at)->not->toBeNull()
        ->and(Activity::query()
            ->where('event', 'user.login')
            ->where('subject_id', $user->id)
            ->count())->toBe(1);
});

test('web recovery code login consumes the code and authenticates once', function () {
    $user = User::factory()->create(['username' => 'web-recovery']);
    webAuthEnableTwoFactor($user, ['single-web-recovery']);

    $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => '123',
    ])->assertRedirect(route('two-factor.login'));

    $this->post(route('two-factor.login.store'), [
        'recovery_code' => 'single-web-recovery',
    ])->assertRedirect(route('home'));

    $this->assertAuthenticatedAs($user);
    expect($user->refresh()->recoveryCodes())
        ->toHaveCount(1)
        ->not->toContain('single-web-recovery');
});

test('blocked and inactive users remain denied by web login', function (string $state, string $messageKey) {
    $user = User::factory()->create($state === 'blocked'
        ? ['blocked_at' => now()]
        : ['is_active' => false]);

    $this->post(route('login.store'), [
        'username' => $user->username,
        'password' => '123',
    ])
        ->assertSessionHasErrors([
            'username' => __($messageKey),
        ]);

    $this->assertGuest();
})->with([
    'blocked' => ['blocked', 'messages.blocked'],
    'inactive' => ['inactive', 'messages.inactive'],
]);

test('existing session middleware ends blocked and inactive browser sessions', function (string $state, string $messageKey) {
    $user = User::factory()->create();
    $this->actingAs($user)->get(route('home'))->assertOk();

    $user->forceFill($state === 'blocked'
        ? ['blocked_at' => now()]
        : ['is_active' => false])->save();

    $this->get(route('home'))
        ->assertRedirect(route('login'))
        ->assertSessionHas('status', __($messageKey));

    $this->assertGuest();
})->with([
    'blocked' => ['blocked', 'messages.session_ended_blocked'],
    'inactive' => ['inactive', 'messages.session_ended_inactive'],
]);

test('web login limiter cannot be bypassed with username casing', function () {
    $user = User::factory()->create(['username' => 'web-rate-limited']);

    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->post(route('login.store'), [
            'username' => $attempt % 2 === 0 ? 'WEB-RATE-LIMITED' : 'web-rate-limited',
            'password' => 'wrong-password',
        ])->assertSessionHasErrors('username');
    }

    $this->post(route('login.store'), [
        'username' => 'web-rate-limited',
        'password' => 'wrong-password',
    ])->assertTooManyRequests();
});
