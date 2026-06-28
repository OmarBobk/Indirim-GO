<?php

use App\Support\BrandLogo;

it('resolves english logo paths', function () {
    expect(BrandLogo::variant('en'))->toBe('en')
        ->and(BrandLogo::lightPath('en'))->toBe('light_en_logo.png')
        ->and(BrandLogo::darkPath('en'))->toBe('dark_en_logo.png');
});

it('resolves arabic logo paths', function () {
    expect(BrandLogo::variant('ar'))->toBe('ar')
        ->and(BrandLogo::lightPath('ar'))->toBe('light_ar_logo.png')
        ->and(BrandLogo::darkPath('ar'))->toBe('dark_ar_logo.png');
});

it('falls back to english for unsupported locales', function () {
    expect(BrandLogo::variant('tr'))->toBe('en')
        ->and(BrandLogo::lightPath('tr'))->toBe('light_en_logo.png');
});

it('uses compact header sizing for english and larger sizing for arabic', function () {
    expect(BrandLogo::headerImageClasses('en'))->toContain('h-10')
        ->and(BrandLogo::headerImageClasses('en'))->not->toContain('min-w-28');

    expect(BrandLogo::headerImageClasses('ar'))->toContain('h-14')
        ->and(BrandLogo::headerImageClasses('ar'))->toContain('min-w-28');
});

it('uses larger footer sizing than header', function () {
    expect(BrandLogo::imageClasses('footer', 'en'))->toContain('h-11')
        ->and(BrandLogo::imageClasses('footer', 'ar'))->toContain('h-16');
});
