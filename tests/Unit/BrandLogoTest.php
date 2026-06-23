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
