<?php

declare(strict_types=1);

use App\Actions\MobileAuth\CreateMobileTwoFactorChallenge;
use App\Enums\Timezone;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Sanctum\PersonalAccessToken;
use Mockery\MockInterface;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

function m11Customer(array $attributes = []): User
{
    $role = Role::firstOrCreate(['name' => 'customer', 'guard_name' => 'web']);
    $user = User::factory()->create($attributes);
    $user->assignRole($role);

    return $user;
}

/**
 * @param  list<string>  $recoveryCodes
 */
function m11EnableTwoFactor(User $user, array $recoveryCodes = ['recovery-code-1']): void
{
    $user->forceFill([
        'two_factor_secret' => encrypt('mobile-test-secret'),
        'two_factor_recovery_codes' => encrypt(json_encode($recoveryCodes, JSON_THROW_ON_ERROR)),
        'two_factor_confirmed_at' => now(),
    ])->save();
}

function m11Bearer(string $plainTextToken): array
{
    return [
        'Authorization' => 'Bearer '.$plainTextToken,
        'Accept' => 'application/json',
    ];
}

function m11ChallengeToken(User $user, ?string $deviceName = 'Test Android'): string
{
    $response = test()->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => '123',
        'device_name' => $deviceName,
    ]);

    $response->assertAccepted();

    return $response->json('data.challenge_token');
}

beforeEach(function () {
    config([
        'cache.default' => 'array',
        'filesystems.disks.public.url' => 'http://localhost/storage',
    ]);
});

test('a customer can log in with username and receive a scoped 30 day token', function () {
    $this->freezeTime();
    $now = CarbonImmutable::now();
    $user = m11Customer([
        'username' => 'mobile-customer',
        'email' => 'mobile@example.com',
        'phone' => '+905551112233',
        'country_code' => '+90',
        'timezone' => Timezone::Turkey,
        'preferred_currency' => 'USD',
        'locale' => 'en',
    ]);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => '123',
        'device_name' => 'Omar Android',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.token.token_type', 'Bearer')
        ->assertJsonPath('data.token.expires_at', $now->addDays(30)->toIso8601String())
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.user.phone', '+905551112233')
        ->assertJsonMissingPath('data.user.roles')
        ->assertJsonMissingPath('data.user.permissions');

    $plainTextToken = $response->json('data.token.access_token');
    $token = PersonalAccessToken::findToken($plainTextToken);

    expect($token)
        ->not->toBeNull()
        ->name->toBe('mobile: Omar Android')
        ->abilities->toBe(['mobile:access'])
        ->and($token?->expires_at?->toIso8601String())
        ->toBe($now->addDays(30)->toIso8601String())
        ->and($user->refresh()->last_login_at)
        ->not->toBeNull()
        ->and(Activity::query()->where('event', 'user.login')->where('subject_id', $user->id)->exists())
        ->toBeTrue();
});

test('login normalizes username exactly as configured by Fortify', function () {
    $user = m11Customer(['username' => 'normalized-user']);

    $this->postJson('/api/v1/auth/login', [
        'username' => 'NORMALIZED-USER',
        'password' => '123',
    ])->assertOk();
});

test('invalid credentials do not reveal whether the username exists', function () {
    $user = m11Customer();

    $existing = $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => 'wrong-password',
    ]);
    $missing = $this->postJson('/api/v1/auth/login', [
        'username' => 'missing-user',
        'password' => 'wrong-password',
    ]);

    $existing
        ->assertUnprocessable()
        ->assertExactJson([
            'message' => __('messages.mobile_api.invalid_credentials'),
            'code' => 'invalid_credentials',
        ]);
    expect($missing->json())->toBe($existing->json());
});

test('login request validation uses the API envelope', function () {
    $this->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('message', __('messages.mobile_api.validation_failed'))
        ->assertJsonValidationErrors(['username', 'password'])
        ->assertJsonMissingPath('code');
});

test('inactive and blocked customers retain localized account denial behavior', function (string $state, string $code) {
    $user = m11Customer();
    $user->forceFill($state === 'inactive'
        ? ['is_active' => false]
        : ['blocked_at' => now()])->save();

    $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => '123',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', $code)
        ->assertJsonPath('message', __("messages.mobile_api.{$code}"));

    expect($user->tokens()->count())->toBe(0);
})->with([
    'inactive' => ['inactive', 'account_inactive'],
    'blocked' => ['blocked', 'account_blocked'],
]);

test('a non customer identity is forbidden from mobile login', function () {
    $role = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
    $user = User::factory()->create();
    $user->assignRole($role);

    $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => '123',
    ])
        ->assertForbidden()
        ->assertExactJson([
            'message' => __('messages.mobile_api.customer_role_required'),
            'code' => 'customer_role_required',
        ]);

    expect($user->tokens()->count())->toBe(0);
});

test('login is throttled by normalized username and IP with retry after', function () {
    for ($attempt = 1; $attempt <= 5; $attempt++) {
        $this->postJson('/api/v1/auth/login', [
            'username' => $attempt % 2 === 0 ? 'RATE-LIMITED' : 'rate-limited',
            'password' => 'wrong-password',
        ])->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/login', [
        'username' => 'rate-limited',
        'password' => 'wrong-password',
    ])
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'too_many_requests');
});

test('unverified customers are not newly blocked from mobile login', function () {
    $user = m11Customer(['email_verified_at' => null]);

    $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => '123',
    ])
        ->assertOk()
        ->assertJsonPath('data.user.email_verified_at', null);
});

test('two factor customers receive a hashed short lived challenge and no token', function () {
    $user = m11Customer();
    m11EnableTwoFactor($user);

    $response = $this->postJson('/api/v1/auth/login', [
        'username' => $user->username,
        'password' => '123',
        'device_name' => 'Secure Phone',
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('data.two_factor_required', true)
        ->assertJsonStructure(['data' => ['challenge_token', 'expires_at']]);

    $challengeToken = $response->json('data.challenge_token');

    expect(strlen($challengeToken))->toBeGreaterThanOrEqual(43)
        ->and(Cache::has(CreateMobileTwoFactorChallenge::cacheKey($challengeToken)))->toBeTrue()
        ->and(Cache::has('mobile-api:two-factor:'.$challengeToken))->toBeFalse()
        ->and($user->tokens()->count())->toBe(0)
        ->and($user->refresh()->last_login_at)->toBeNull();
});

test('a valid authenticator code completes the challenge once', function () {
    $user = m11Customer();
    m11EnableTwoFactor($user);
    $challengeToken = m11ChallengeToken($user, 'Authenticator Phone');

    $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('verify')->once()->andReturnTrue();
    });

    $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challengeToken,
        'code' => '123456',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.user.id', $user->id)
        ->assertJsonPath('data.token.token_type', 'Bearer');

    expect($user->tokens()->count())->toBe(1)
        ->and($user->refresh()->last_login_at)->not->toBeNull();
});

test('a valid recovery code is consumed under the challenge lock', function () {
    $user = m11Customer();
    m11EnableTwoFactor($user, ['single-use-recovery']);
    $challengeToken = m11ChallengeToken($user);

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challengeToken,
        'recovery_code' => 'single-use-recovery',
    ])->assertOk();

    $recoveryCodes = $user->refresh()->recoveryCodes();

    expect($recoveryCodes)
        ->toHaveCount(1)
        ->not->toContain('single-use-recovery')
        ->and($user->tokens()->count())->toBe(1);
});

test('invalid authenticator and recovery codes fail safely without issuing tokens', function (string $field, string $value, string $code) {
    $user = m11Customer();
    m11EnableTwoFactor($user, ['valid-recovery']);
    $challengeToken = m11ChallengeToken($user);

    if ($field === 'code') {
        $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $mock): void {
            $mock->shouldReceive('verify')->once()->andReturnFalse();
        });
    }

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challengeToken,
        $field => $value,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', $code);

    expect($user->tokens()->count())->toBe(0);
})->with([
    'authenticator' => ['code', '123456', 'invalid_two_factor_code'],
    'recovery' => ['recovery_code', 'invalid-recovery', 'invalid_recovery_code'],
]);

test('two factor input requires exactly one code type', function (array $payload) {
    $this->postJson('/api/v1/auth/two-factor-challenge', array_merge([
        'challenge_token' => str_repeat('a', 43),
    ], $payload))
        ->assertUnprocessable()
        ->assertJsonPath('message', __('messages.mobile_api.validation_failed'));
})->with([
    'neither' => [[]],
    'both' => [['code' => '123456', 'recovery_code' => 'recovery-code']],
]);

test('expired challenges cannot be completed', function () {
    $user = m11Customer();
    m11EnableTwoFactor($user);
    $challengeToken = m11ChallengeToken($user);

    $this->travel(6)->minutes();

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challengeToken,
        'code' => '123456',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_two_factor_challenge');

    expect($user->tokens()->count())->toBe(0);
});

test('a successful challenge cannot be replayed', function () {
    $user = m11Customer();
    m11EnableTwoFactor($user);
    $challengeToken = m11ChallengeToken($user);

    $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('verify')->once()->andReturnTrue();
    });

    $payload = ['challenge_token' => $challengeToken, 'code' => '123456'];

    $this->postJson('/api/v1/auth/two-factor-challenge', $payload)->assertOk();
    $this->postJson('/api/v1/auth/two-factor-challenge', $payload)
        ->assertUnprocessable()
        ->assertJsonPath('code', 'invalid_two_factor_challenge');

    expect($user->tokens()->count())->toBe(1);
});

test('a challenge is destroyed at its invalid attempt limit', function () {
    $user = m11Customer();
    m11EnableTwoFactor($user);
    $challengeToken = m11ChallengeToken($user);

    $this->mock(TwoFactorAuthenticationProvider::class, function (MockInterface $mock): void {
        $mock->shouldReceive('verify')->times(5)->andReturnFalse();
    });

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $this->postJson('/api/v1/auth/two-factor-challenge', [
            'challenge_token' => $challengeToken,
            'code' => '123456',
        ])->assertJsonPath('code', 'invalid_two_factor_code');
    }

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challengeToken,
        'code' => '123456',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('code', 'two_factor_attempts_exceeded');

    $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challengeToken,
        'code' => '123456',
    ])->assertJsonPath('code', 'invalid_two_factor_challenge');

    expect($user->tokens()->count())->toBe(0);
});

test('two factor challenge requests have a dedicated rate limit', function () {
    $payload = [
        'challenge_token' => str_repeat('x', 43),
        'code' => '123456',
    ];

    for ($attempt = 1; $attempt <= 10; $attempt++) {
        $this->postJson('/api/v1/auth/two-factor-challenge', $payload)
            ->assertUnprocessable();
    }

    $this->postJson('/api/v1/auth/two-factor-challenge', $payload)
        ->assertTooManyRequests()
        ->assertHeader('Retry-After')
        ->assertJsonPath('code', 'too_many_requests');
});

test('account status and customer role are rechecked at challenge completion', function (string $change, int $status, string $code) {
    $user = m11Customer();
    m11EnableTwoFactor($user);
    $challengeToken = m11ChallengeToken($user);

    if ($change === 'blocked') {
        $user->forceFill(['blocked_at' => now()])->save();
    } else {
        $user->syncRoles([]);
    }

    $response = $this->postJson('/api/v1/auth/two-factor-challenge', [
        'challenge_token' => $challengeToken,
        'recovery_code' => 'recovery-code-1',
    ]);

    $response->assertStatus($status)->assertJsonPath('code', $code);
    expect($user->tokens()->count())->toBe(0);
})->with([
    'blocked' => ['blocked', 422, 'account_blocked'],
    'role removed' => ['role', 403, 'customer_role_required'],
]);

test('me returns only the approved mobile profile fields', function () {
    $user = m11Customer([
        'profile_photo' => 'profiles/customer.jpg',
        'commission_rate_percent' => '17.50',
    ]);
    $token = $user->createToken('mobile', ['mobile:access'], now()->addDays(30));

    $response = $this->withHeaders(m11Bearer($token->plainTextToken))->getJson('/api/v1/me');

    $response
        ->assertOk()
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'username',
                'email',
                'phone',
                'country_code',
                'locale',
                'preferred_currency',
                'timezone',
                'profile_photo_url',
                'email_verified_at',
            ],
        ])
        ->assertJsonCount(11, 'data')
        ->assertJsonPath('data.profile_photo_url', 'http://localhost/storage/profiles/customer.jpg');

    foreach ([
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'tokens',
        'commission_rate_percent',
        'wallet',
        'balance',
        'roles',
        'permissions',
        'referral_code',
        'referred_by_user_id',
        'loyalty_tier',
        'is_active',
        'blocked_at',
    ] as $sensitiveField) {
        $response->assertJsonMissingPath('data.'.$sensitiveField);
    }
});

test('unsafe profile photo values are returned as null', function () {
    $user = m11Customer(['profile_photo' => 'https://evil.example/customer.jpg']);
    $token = $user->createToken('mobile', ['mobile:access'], now()->addDays(30));

    $this->withHeaders(m11Bearer($token->plainTextToken))
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.profile_photo_url', null);
});

test('missing invalid expired and revoked tokens are rejected', function (string $case) {
    $user = m11Customer();
    $headers = ['Accept' => 'application/json'];

    if ($case === 'invalid') {
        $headers = m11Bearer('999|not-a-real-token');
    } elseif ($case === 'expired') {
        $headers = m11Bearer(
            $user->createToken('expired', ['mobile:access'], now()->subMinute())->plainTextToken
        );
    } elseif ($case === 'revoked') {
        $token = $user->createToken('revoked', ['mobile:access'], now()->addDays(30));
        $headers = m11Bearer($token->plainTextToken);
        $token->accessToken->delete();
    }

    $this->withHeaders($headers)
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'unauthenticated');
})->with(['missing', 'invalid', 'expired', 'revoked']);

test('tokens without the mobile ability are forbidden', function () {
    $user = m11Customer();
    $token = $user->createToken('other-client', ['orders:read'], now()->addDays(30));

    $this->withHeaders(m11Bearer($token->plainTextToken))
        ->getJson('/api/v1/me')
        ->assertForbidden()
        ->assertJsonPath('code', 'missing_mobile_ability');
});

test('a blocked account loses its current mobile token on the next request', function () {
    $user = m11Customer();
    $token = $user->createToken('mobile', ['mobile:access'], now()->addDays(30));
    $tokenId = $token->accessToken->getKey();
    $user->forceFill(['blocked_at' => now()])->save();

    $this->withHeaders(m11Bearer($token->plainTextToken))
        ->getJson('/api/v1/me')
        ->assertUnauthorized()
        ->assertJsonPath('code', 'account_blocked');

    expect(PersonalAccessToken::query()->find($tokenId))->toBeNull();
});

test('logout revokes only the current access token', function () {
    $user = m11Customer();
    $current = $user->createToken('current', ['mobile:access'], now()->addDays(30));
    $other = $user->createToken('other', ['mobile:access'], now()->addDays(30));

    $this->withHeaders(m11Bearer($current->plainTextToken))
        ->postJson('/api/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('data.message', __('messages.mobile_api.logged_out'));

    expect(PersonalAccessToken::query()->find($current->accessToken->getKey()))->toBeNull()
        ->and(PersonalAccessToken::query()->find($other->accessToken->getKey()))->not->toBeNull();
});

test('API errors and validation are localized in English and Arabic', function (string $locale, string $expectedValidation, string $expectedCredentials) {
    $headers = ['Accept-Language' => $locale];

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [])
        ->assertUnprocessable()
        ->assertJsonPath('message', $expectedValidation);

    $this->withHeaders($headers)
        ->postJson('/api/v1/auth/login', [
            'username' => 'missing-user-'.$locale,
            'password' => 'wrong-password',
        ])
        ->assertUnprocessable()
        ->assertJsonPath('message', $expectedCredentials);
})->with([
    'English' => [
        'en',
        'The given data was invalid.',
        'These credentials do not match our records.',
    ],
    'Arabic' => [
        'ar',
        'البيانات المقدمة غير صالحة.',
        'بيانات الاعتماد هذه غير متطابقة مع سجلاتنا.',
    ],
]);

test('existing web username authentication still works', function () {
    $user = User::factory()->create(['username' => 'web-auth-user']);

    $this->post(route('login.store'), [
        'username' => 'WEB-AUTH-USER',
        'password' => '123',
    ])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('home', absolute: false));

    $this->assertAuthenticatedAs($user);
    expect(Hash::check('123', $user->password))->toBeTrue();
});
