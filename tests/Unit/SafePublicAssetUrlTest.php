<?php

declare(strict_types=1);

use App\Support\Api\V1\SafePublicAssetUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    config(['app.url' => 'http://localhost']);
});

dataset('safe_public_asset_url_cases', [
    'valid webp' => ['images/packages/ok.webp', 'http://localhost/images/packages/ok.webp'],
    'valid with space encoded' => ['images/packages/my%20pack.webp', 'http://localhost/images/packages/my pack.webp'],
    'null path' => [null, null],
    'empty' => ['', null],
    'whitespace' => ['   ', null],
    'literal traversal' => ['../etc/passwd', null],
    'nested traversal' => ['images/../../etc/passwd', null],
    'backslash traversal' => ['images\\..\\secret.png', null],
    'encoded traversal lower' => ['%2e%2e/etc/passwd', null],
    'encoded traversal upper' => ['%2E%2E/etc/passwd', null],
    'encoded nested' => ['images/%2e%2e/secret.png', null],
    'double encoded' => ['%252e%252e/etc/passwd', null],
    'mixed encoded' => ['%2e./etc/passwd', null],
    'null byte literal' => ["images/ok\0.webp", null],
    'null byte encoded' => ['images/ok%00.webp', null],
    'scheme https' => ['https://evil.test/x.png', null],
    'scheme javascript' => ['javascript:alert(1)', null],
    'scheme relative' => ['//evil.test/x.png', null],
    'svg' => ['images/icons/category-placeholder.svg', null],
    'svg encoded suffix' => ['images/icons/x.%73vg', null],
    'malformed percent' => ['images/%zz/x.png', null],
    'incomplete percent' => ['images/%2/x.png', null],
]);

test('safe public asset url hardening', function (mixed $input, ?string $expected): void {
    expect(SafePublicAssetUrl::fromRelativePath($input))->toBe($expected);
})->with('safe_public_asset_url_cases');
