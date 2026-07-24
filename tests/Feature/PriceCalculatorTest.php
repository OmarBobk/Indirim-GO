<?php

use App\Models\PricingRule;
use App\Services\PriceCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

test('calculates retail and wholesale from entry price using active rule', function () {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 1000,
        'wholesale_percentage' => 2,
        'retail_percentage' => 10,
        'priority' => 0,
        'is_active' => true,
    ]);

    $calculator = app(PriceCalculator::class);
    $result = $calculator->calculate(100.0);

    expect($result['retail_price'])->toBe(110.0);
    expect($result['wholesale_price'])->toBe(102.0);
});

test('sub cent entry rounds to zero at two decimals but preserves value at higher scale', function () {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 5,
        'retail_percentage' => 10,
        'priority' => 0,
        'is_active' => true,
    ]);

    $calculator = app(PriceCalculator::class);

    $two = $calculator->calculate(0.001783164381826);
    expect($two['retail_price'])->toBe(0.0);
    expect($two['wholesale_price'])->toBe(0.0);

    $six = $calculator->calculate(0.001783164381826, 6);
    expect($six['retail_price'])->toBeGreaterThan(0.0);
    expect($six['wholesale_price'])->toBeGreaterThan(0.0);
    expect($six['retail_price'])->toBe(round(0.001783164381826 * 1.10, 6, PHP_ROUND_HALF_EVEN));
});

test('applies bankers rounding to prices', function () {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 33.33,
        'retail_percentage' => 33.33,
        'priority' => 0,
        'is_active' => true,
    ]);

    $calculator = app(PriceCalculator::class);
    $result = $calculator->calculate(10.0);

    expect($result['retail_price'])->toBe(13.33);
    expect($result['wholesale_price'])->toBe(13.33);
});

test('selects first matching rule by priority', function () {
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 1000,
        'wholesale_percentage' => 0,
        'retail_percentage' => 5,
        'priority' => 10,
        'is_active' => true,
    ]);
    PricingRule::create([
        'min_price' => 0,
        'max_price' => 1000,
        'wholesale_percentage' => 0,
        'retail_percentage' => 10,
        'priority' => 0,
        'is_active' => true,
    ]);

    $calculator = app(PriceCalculator::class);
    $result = $calculator->calculate(100.0);

    expect($result['retail_price'])->toBe(110.0);
});

test('throws when no rule matches entry price', function () {
    PricingRule::query()->delete();
    PricingRule::create([
        'min_price' => 100,
        'max_price' => 200,
        'wholesale_percentage' => 0,
        'retail_percentage' => 0,
        'priority' => 0,
        'is_active' => true,
    ]);

    $calculator = app(PriceCalculator::class);

    $calculator->calculate(50.0);
})->throws(\InvalidArgumentException::class);

test('caches pricing rule lookup for same entry price', function () {
    Cache::flush();

    PricingRule::query()->create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 2,
        'retail_percentage' => 10,
        'priority' => 0,
        'is_active' => true,
    ]);

    DB::enableQueryLog();

    $calculator = app(PriceCalculator::class);
    $calculator->calculate(123.45);
    $calculator->calculate(123.45);

    $queries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains(strtolower((string) ($query['query'] ?? '')), 'pricing_rules'))
        ->values();

    expect($queries->count())->toBe(1);
});

test('user pricing rules take precedence over global rules', function () {
    PricingRule::query()->create([
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 2,
        'retail_percentage' => 10,
        'priority' => 0,
        'is_active' => true,
    ]);

    $user = \App\Models\User::factory()->create();

    \App\Models\UserPricingRule::query()->create([
        'user_id' => $user->id,
        'min_price' => 0,
        'max_price' => 999999.99,
        'wholesale_percentage' => 3,
        'retail_percentage' => 5,
        'priority' => 0,
        'is_active' => true,
    ]);

    $calculator = app(PriceCalculator::class);
    $result = $calculator->calculate(100.0, 2, $user);

    expect($result['retail_price'])->toBe(105.0);
    expect($result['wholesale_price'])->toBe(103.0);
    expect($result['uses_user_pricing'])->toBeTrue();
});
