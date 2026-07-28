<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

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

test('mobile security configuration matches the published token semantics', function () {
    expect(config('mobile_api.token.ability'))->toBe('mobile:access')
        ->and(config('mobile_api.token.lifetime_days'))->toBe(30)
        ->and(config('mobile_api.two_factor_challenge.lifetime_minutes'))->toBe(5)
        ->and(config('mobile_api.two_factor_challenge.max_attempts'))->toBe(5)
        ->and(config('sanctum.expiration'))->toBeNull();
});
