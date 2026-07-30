<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\FrontendMoney;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(Tests\TestCase::class, RefreshDatabase::class);

/**
 * @return non-empty-string
 */
function referenceCurrencyFormat(float $amount, string $currencyCode, int $minFraction, int $maxFraction): string
{
    $locale = $currencyCode === 'TRY' ? 'tr-TR' : 'en-US';
    $formatter = new \NumberFormatter($locale, \NumberFormatter::CURRENCY);
    $formatter->setAttribute(\NumberFormatter::MIN_FRACTION_DIGITS, $minFraction);
    $formatter->setAttribute(\NumberFormatter::MAX_FRACTION_DIGITS, $maxFraction);
    $out = $formatter->formatCurrency($amount, $currencyCode);
    expect($out)->not->toBeFalse();

    return $out;
}

beforeEach(function (): void {
    if (! extension_loaded('intl')) {
        $this->markTestSkipped('The intl extension is required for FrontendMoney locale formatting.');
    }

    WebsiteSetting::query()->delete();
    WebsiteSetting::query()->create([
        'contact_email' => null,
        'primary_phone' => null,
        'secondary_phone' => null,
        'prices_visible' => true,
        'usd_try_rate' => 40,
        'usd_try_rate_updated_at' => now(),
    ]);
});

test('USD preference keeps amounts in en-US USD', function (): void {
    $user = User::factory()->create(['preferred_currency' => 'USD']);
    $money = FrontendMoney::for($user);
    $amount = 1234.5;

    expect($money->format($amount, 'USD', 2))
        ->toBe(referenceCurrencyFormat($amount, 'USD', 2, 2));
});

test('TRY preference converts USD input using admin rate and formats tr-TR TRY', function (): void {
    $user = User::factory()->create(['preferred_currency' => 'TRY']);
    $money = FrontendMoney::for($user);

    expect($money->format(10, 'USD', 2))
        ->toBe(referenceCurrencyFormat(400, 'TRY', 2, 2));
});

test('displayForUsdAmount returns actual display currency and formatted value', function (): void {
    $usdUser = User::factory()->create(['preferred_currency' => 'USD']);
    $tryUser = User::factory()->create(['preferred_currency' => 'TRY']);

    expect(FrontendMoney::for($usdUser)->displayForUsdAmount(12.5, 2))->toBe([
        'currency' => 'USD',
        'formatted' => referenceCurrencyFormat(12.5, 'USD', 2, 2),
    ])->and(FrontendMoney::for($tryUser)->displayForUsdAmount(10, 2))->toBe([
        'currency' => 'TRY',
        'formatted' => referenceCurrencyFormat(400, 'TRY', 2, 2),
    ]);
});

test('zero fraction digits match reference for tier-style thresholds', function (): void {
    $user = User::factory()->create(['preferred_currency' => 'TRY']);
    $money = FrontendMoney::for($user);

    expect($money->format(1500, 'USD', 0))
        ->toBe(referenceCurrencyFormat(60_000, 'TRY', 0, 0));
});

test('per-unit style uses min two max six fraction digits', function (): void {
    $user = User::factory()->create(['preferred_currency' => 'USD']);
    $money = FrontendMoney::for($user);
    $amount = 1.234567;

    expect($money->format($amount, 'USD', 6))
        ->toBe(referenceCurrencyFormat($amount, 'USD', 2, 6));
});
