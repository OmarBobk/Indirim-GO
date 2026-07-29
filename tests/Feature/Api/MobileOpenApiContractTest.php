<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

test('the authoritative OpenAPI contract documents the complete M1 surface', function () {
    $contractPath = base_path('docs/api/v1/openapi.yaml');
    $contract = file_get_contents($contractPath);

    expect($contractPath)->toBeFile()
        ->and($contract)->toBeString()
        ->and($contract)
        ->toContain(
            'openapi: 3.1.0',
            '  /auth/login:',
            '  /auth/two-factor-challenge:',
            '  /auth/logout:',
            '  /me:',
            'Accept-Language',
            'bearerAuth:',
            'mobile:access',
            'Exactly 30 days after token issue.',
            'approximately five minutes',
            'invalid_credentials',
            'invalid_two_factor_challenge',
            'two_factor_attempts_exceeded',
            'missing_mobile_ability',
            'profile_photo_url',
            'بيانات الاعتماد هذه غير متطابقة مع سجلاتنا.',
        );
});

test('implemented mobile routes and middleware remain aligned with OpenAPI', function () {
    $routes = [
        'api.v1.auth.login' => ['POST', 'api/v1/auth/login'],
        'api.v1.auth.two-factor-challenge' => ['POST', 'api/v1/auth/two-factor-challenge'],
        'api.v1.auth.logout' => ['POST', 'api/v1/auth/logout'],
        'api.v1.me' => ['GET', 'api/v1/me'],
    ];

    foreach ($routes as $name => [$method, $uri]) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)
            ->not->toBeNull()
            ->and($route?->uri())->toBe($uri)
            ->and($route?->methods())->toContain($method);
    }

    $protectedMiddleware = Route::getRoutes()->getByName('api.v1.me')?->gatherMiddleware() ?? [];

    expect($protectedMiddleware)
        ->toContain('auth:sanctum')
        ->toContain('abilities:mobile:access')
        ->toContain('mobile.account');
});

test('OpenAPI requests responses security and user fields match the implementation', function () {
    $specification = Yaml::parseFile(base_path('docs/api/v1/openapi.yaml'));
    $paths = $specification['paths'];
    $schemas = $specification['components']['schemas'];

    expect(array_keys($paths))->toBe([
        '/auth/login',
        '/auth/two-factor-challenge',
        '/auth/logout',
        '/me',
    ])->and(array_keys($paths['/auth/login']['post']['responses']))->toBe([
        200,
        202,
        403,
        422,
        429,
    ])->and(array_keys($paths['/auth/two-factor-challenge']['post']['responses']))->toBe([
        200,
        403,
        422,
        429,
    ])->and(array_keys($paths['/auth/logout']['post']['responses']))->toBe([
        200,
        401,
        403,
    ])->and(array_keys($paths['/me']['get']['responses']))->toBe([
        200,
        401,
        403,
    ]);

    expect($schemas['LoginRequest']['required'])->toBe(['username', 'password'])
        ->and(array_keys($schemas['LoginRequest']['properties']))->toBe([
            'username',
            'password',
            'device_name',
        ])
        ->and($schemas['LoginRequest']['properties']['username']['minLength'])->toBe(1)
        ->and($schemas['LoginRequest']['properties']['password']['minLength'])->toBe(1)
        ->and($schemas['LoginRequest']['properties']['device_name']['maxLength'])->toBe(80)
        ->and($schemas['TwoFactorChallengeRequest']['required'])->toBe(['challenge_token'])
        ->and(array_keys($schemas['TwoFactorChallengeRequest']['properties']))->toBe([
            'challenge_token',
            'code',
            'recovery_code',
        ])
        ->and($schemas['TwoFactorChallengeRequest']['properties']['code']['type'])
        ->toBe(['string', 'null'])
        ->and($schemas['TwoFactorChallengeRequest']['properties']['recovery_code']['type'])
        ->toBe(['string', 'null'])
        ->and($schemas['TwoFactorChallengeRequest']['properties']['recovery_code']['minLength'])->toBe(1)
        ->and($schemas['TwoFactorChallengeRequest']['oneOf'])->toHaveCount(2);

    expect($paths['/auth/logout']['post']['security'])->toBe([['bearerAuth' => []]])
        ->and($paths['/me']['get']['security'])->toBe([['bearerAuth' => []]])
        ->and($specification['components']['securitySchemes']['bearerAuth']['scheme'])->toBe('bearer')
        ->and($schemas['Token']['properties']['token_type']['const'])->toBe('Bearer')
        ->and($schemas['Token']['properties']['access_token'])->not->toHaveKey('writeOnly')
        ->and($schemas['Token']['properties']['expires_at']['description'])
        ->toContain('Exactly 30 days');

    expect($schemas['MobileUser']['required'])->toBe([
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
    ])->and(array_keys($schemas['MobileUser']['properties']))->toBe($schemas['MobileUser']['required'])
        ->and($schemas['MobileUser']['properties']['locale']['enum'])->toBe(['ar', 'en']);

    expect($schemas['ApiError']['properties']['code']['enum'])->toContain(
        'invalid_credentials',
        'account_inactive',
        'account_blocked',
        'customer_role_required',
        'invalid_two_factor_challenge',
        'invalid_two_factor_code',
        'invalid_recovery_code',
        'two_factor_attempts_exceeded',
        'unauthenticated',
        'missing_mobile_ability',
        'too_many_requests',
    )->and($paths['/auth/login']['post']['parameters'][0]['$ref'])
        ->toBe('#/components/parameters/AcceptLanguage')
        ->and($paths['/auth/two-factor-challenge']['post']['parameters'][0]['$ref'])
        ->toBe('#/components/parameters/AcceptLanguage')
        ->and($paths['/auth/logout']['post']['parameters'][0]['$ref'])
        ->toBe('#/components/parameters/AcceptLanguage')
        ->and($paths['/me']['get']['parameters'][0]['$ref'])
        ->toBe('#/components/parameters/AcceptLanguage');
});

test('mobile security configuration matches the published token semantics', function () {
    expect(config('mobile_api.token.ability'))->toBe('mobile:access')
        ->and(config('mobile_api.token.lifetime_days'))->toBe(30)
        ->and(config('mobile_api.two_factor_challenge.lifetime_minutes'))->toBe(5)
        ->and(config('mobile_api.two_factor_challenge.max_attempts'))->toBe(5)
        ->and(config('sanctum.expiration'))->toBeNull();
});
