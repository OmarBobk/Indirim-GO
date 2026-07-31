<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

test('the authoritative OpenAPI contract documents the complete M1 M2.1 and M3.1 surface', function () {
    $contractPath = base_path('docs/api/v1/openapi.yaml');
    $contract = file_get_contents($contractPath);

    expect($contractPath)->toBeFile()
        ->and($contract)->toBeString()
        ->and($contract)
        ->toContain(
            'openapi: 3.1.0',
            'version: 1.2.0',
            '  /auth/login:',
            '  /auth/two-factor-challenge:',
            '  /auth/logout:',
            '  /me:',
            '  /catalog/home:',
            '  /packages:',
            '  /packages/{package}:',
            '  /wallet/summary:',
            '  /checkout/quote:',
            '  /checkout:',
            '  /checkout/status:',
            '  /orders/{order_number}:',
            'Accept-Language',
            'Idempotency-Key',
            'bearerAuth:',
            'mobile:access',
            'Exactly 30 days after token issue.',
            'approximately five minutes',
            'invalid_credentials',
            'invalid_two_factor_challenge',
            'two_factor_attempts_exceeded',
            'missing_mobile_ability',
            'package_not_found',
            'purchasing_unavailable',
            'price_changed',
            'insufficient_wallet_balance',
            'idempotency_conflict',
            'checkout_attempt_not_found',
            'prices_visible',
            'from_price',
            'minimum_price',
            'quote_fingerprint',
            'requirements_schema',
            'PackageRequirementField',
            'MoneyDisplay',
            'profile_photo_url',
            'بيانات الاعتماد هذه غير متطابقة مع سجلاتنا.',
            'الحزمة غير موجودة.',
            '72 hours',
        );
});

test('implemented mobile routes and middleware remain aligned with OpenAPI', function () {
    $routes = [
        'api.v1.auth.login' => ['POST', 'api/v1/auth/login'],
        'api.v1.auth.two-factor-challenge' => ['POST', 'api/v1/auth/two-factor-challenge'],
        'api.v1.auth.logout' => ['POST', 'api/v1/auth/logout'],
        'api.v1.me' => ['GET', 'api/v1/me'],
        'api.v1.catalog.home' => ['GET', 'api/v1/catalog/home'],
        'api.v1.packages.index' => ['GET', 'api/v1/packages'],
        'api.v1.packages.show' => ['GET', 'api/v1/packages/{package}'],
        'api.v1.wallet.summary' => ['GET', 'api/v1/wallet/summary'],
        'api.v1.checkout.quote' => ['POST', 'api/v1/checkout/quote'],
        'api.v1.checkout' => ['POST', 'api/v1/checkout'],
        'api.v1.checkout.status' => ['GET', 'api/v1/checkout/status'],
        'api.v1.orders.show' => ['GET', 'api/v1/orders/{order_number}'],
    ];

    foreach ($routes as $name => [$method, $uri]) {
        $route = Route::getRoutes()->getByName($name);

        expect($route)
            ->not->toBeNull()
            ->and($route?->uri())->toBe($uri)
            ->and($route?->methods())->toContain($method);
    }

    $protectedMiddleware = Route::getRoutes()->getByName('api.v1.me')?->gatherMiddleware() ?? [];
    $catalogMiddleware = Route::getRoutes()->getByName('api.v1.catalog.home')?->gatherMiddleware() ?? [];
    $purchaseReadMiddleware = Route::getRoutes()->getByName('api.v1.wallet.summary')?->gatherMiddleware() ?? [];
    $purchaseWriteMiddleware = Route::getRoutes()->getByName('api.v1.checkout')?->gatherMiddleware() ?? [];

    expect($protectedMiddleware)
        ->toContain('auth:sanctum')
        ->toContain('abilities:mobile:access')
        ->toContain('mobile.account')
        ->and($catalogMiddleware)
        ->toContain('throttle:mobile-catalog')
        ->and($purchaseReadMiddleware)
        ->toContain('throttle:mobile-purchase-read')
        ->and($purchaseWriteMiddleware)
        ->toContain('throttle:mobile-purchase-write');
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
        '/catalog/home',
        '/packages',
        '/packages/{package}',
        '/wallet/summary',
        '/checkout/quote',
        '/checkout',
        '/checkout/status',
        '/orders/{order_number}',
    ])->and(array_keys($paths['/auth/login']['post']['responses']))->toBe([
        200,
        202,
        403,
        422,
        429,
    ])->and(array_keys($paths['/catalog/home']['get']['responses']))->toBe([
        200,
        401,
        403,
        429,
    ])->and(array_keys($paths['/packages']['get']['responses']))->toBe([
        200,
        401,
        403,
        422,
        429,
    ])->and(array_keys($paths['/packages/{package}']['get']['responses']))->toBe([
        200,
        401,
        403,
        404,
        429,
    ])->and(array_keys($paths['/checkout']['post']['responses']))->toContain(200, 202, 409, 422, 429)
        ->and($schemas['CheckoutQuoteRequest']['properties']['items']['maxItems'])->toBe(1)
        ->and($schemas['CheckoutQuoteRequest']['properties']['items']['minItems'])->toBe(1)
        ->and($schemas['PackageDetail']['required'])->toContain('requirements')
        ->and($schemas['PackageRequirementField']['required'])->toBe([
            'key',
            'label',
            'input_type',
            'required',
            'max_length',
            'options',
        ]);

    expect($schemas['LoginRequest']['required'])->toBe(['username', 'password'])
        ->and(array_keys($schemas['LoginRequest']['properties']))->toBe([
            'username',
            'password',
            'device_name',
        ])
        ->and($paths['/auth/logout']['post']['security'])->toBe([['bearerAuth' => []]])
        ->and($paths['/me']['get']['security'])->toBe([['bearerAuth' => []]])
        ->and($paths['/catalog/home']['get']['security'])->toBe([['bearerAuth' => []]])
        ->and($paths['/packages']['get']['security'])->toBe([['bearerAuth' => []]])
        ->and($paths['/packages/{package}']['get']['security'])->toBe([['bearerAuth' => []]])
        ->and($paths['/wallet/summary']['get']['security'])->toBe([['bearerAuth' => []]])
        ->and($paths['/checkout']['post']['security'])->toBe([['bearerAuth' => []]])
        ->and($specification['components']['securitySchemes']['bearerAuth']['scheme'])->toBe('bearer')
        ->and($schemas['Token']['properties']['token_type']['const'])->toBe('Bearer')
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

    expect($schemas['Money']['required'])->toBe(['amount', 'currency', 'display'])
        ->and($schemas['Money']['properties']['currency']['const'])->toBe('USD')
        ->and($schemas['MoneyDisplay']['required'])->toBe(['currency', 'formatted'])
        ->and($schemas['PackageSummary']['required'])->toContain('from_price')
        ->and($schemas['ProductOption']['required'])->toContain('minimum_price')
        ->and($schemas['CatalogMeta']['required'])->toBe(['prices_visible'])
        ->and($schemas['PackageListMeta']['required'])->toBe(['prices_visible', 'pagination'])
        ->and($schemas['OffsetPagination']['required'])->toBe(['page', 'per_page', 'total', 'last_page']);

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
        'package_not_found',
        'purchasing_unavailable',
        'product_unavailable',
        'invalid_custom_amount',
        'price_changed',
        'insufficient_wallet_balance',
        'idempotency_conflict',
        'checkout_attempt_not_found',
        'order_not_found',
    )->and($paths['/auth/login']['post']['parameters'][0]['$ref'])
        ->toBe('#/components/parameters/AcceptLanguage')
        ->and($paths['/catalog/home']['get']['parameters'][0]['$ref'])
        ->toBe('#/components/parameters/AcceptLanguage')
        ->and($paths['/packages']['get']['parameters'][0]['$ref'])
        ->toBe('#/components/parameters/AcceptLanguage')
        ->and($paths['/packages/{package}']['get']['parameters'][0]['$ref'])
        ->toBe('#/components/parameters/AcceptLanguage');
});

test('mobile security configuration matches the published token semantics', function () {
    expect(config('mobile_api.token.ability'))->toBe('mobile:access')
        ->and(config('mobile_api.token.lifetime_days'))->toBe(30)
        ->and(config('mobile_api.two_factor_challenge.lifetime_minutes'))->toBe(5)
        ->and(config('mobile_api.two_factor_challenge.max_attempts'))->toBe(5)
        ->and(config('mobile_api.checkout.idempotency_retention_hours'))->toBe(72)
        ->and(config('sanctum.expiration'))->toBeNull();
});
