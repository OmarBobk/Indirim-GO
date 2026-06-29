<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\LoyaltyTierConfig;
use App\Models\PricingRule;
use App\Models\Product;
use App\Models\User;
use App\Models\UserPricingRule;

/**
 * Snapshot of pricing rules + user flags for instant buy-now estimates in Alpine.
 * Mirrors PriceCalculator + CustomerPriceService for computed entry totals (custom amount × entry_price).
 */
final class BuyNowClientPricingContext
{
    /**
     * @return array{
     *   client_pricable: bool,
     *   entry_price_per_unit: float|null,
     *   rules: list<array{min: float, max: float, retail_pct: float, wholesale_pct: float}>,
     *   use_wholesale: bool,
     *   loyalty_discount_percent: float,
     *   uses_user_pricing: bool
     * }
     */
    public static function build(User $user, Product $product): array
    {
        $userRules = UserPricingRule::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->get(['min_price', 'max_price', 'retail_percentage', 'wholesale_percentage']);

        $usesUserPricing = $userRules->isNotEmpty();

        $sourceRules = $usesUserPricing
            ? $userRules
            : PricingRule::query()
                ->where('is_active', true)
                ->orderBy('priority')
                ->get(['min_price', 'max_price', 'retail_percentage', 'wholesale_percentage']);

        $rules = $sourceRules
            ->map(fn ($r): array => [
                'min' => (float) $r->min_price,
                'max' => (float) $r->max_price,
                'retail_pct' => (float) $r->retail_percentage,
                'wholesale_pct' => (float) $r->wholesale_percentage,
            ])
            ->values()
            ->all();

        $loyaltyDiscount = 0.0;
        $role = $user->loyaltyRole();
        if ($role !== null) {
            $tierName = $user->loyalty_tier?->value ?? 'bronze';
            $tier = LoyaltyTierConfig::query()->forRole($role)->where('name', $tierName)->first();
            if ($tier !== null) {
                $loyaltyDiscount = (float) $tier->discount_percentage;
            }
        }

        $entry = $product->entry_price !== null ? (float) $product->entry_price : null;
        $clientPricable = $entry !== null
            && $entry > 0
            && $rules !== [];

        return [
            'client_pricable' => $clientPricable,
            'entry_price_per_unit' => $entry,
            'rules' => $rules,
            'use_wholesale' => $user->hasRole('salesperson'),
            'loyalty_discount_percent' => $loyaltyDiscount,
            'uses_user_pricing' => $usesUserPricing,
        ];
    }

    /**
     * Same algorithm as Alpine `computeBuyNowFinal` — used in tests for parity with PricingEngine.
     */
    public static function previewFinalPrice(int $amount, array $context): ?float
    {
        if (! ($context['client_pricable'] ?? false)) {
            return null;
        }

        $entryPerUnit = $context['entry_price_per_unit'] ?? null;
        if ($entryPerUnit === null || (float) $entryPerUnit <= 0) {
            return null;
        }

        $computedEntryTotal = self::multiplyAmountByEntryPrice($amount, (float) $entryPerUnit);
        $rule = self::resolveRule($computedEntryTotal, $context['rules'] ?? []);
        if ($rule === null) {
            return null;
        }

        $useWholesale = (bool) ($context['use_wholesale'] ?? false);
        $pct = $useWholesale ? (float) $rule['wholesale_pct'] : (float) $rule['retail_pct'];
        $basePrice = self::bankersRound($computedEntryTotal * (1 + $pct / 100), 2);

        $loyaltyPct = (float) ($context['loyalty_discount_percent'] ?? 0);
        $discountAmount = self::bankersRound($basePrice * $loyaltyPct / 100, 2);
        $finalPrice = self::bankersRound($basePrice - $discountAmount, 2);

        if ($finalPrice < $computedEntryTotal) {
            $finalPrice = self::bankersRound($computedEntryTotal, 2);
        }

        return $finalPrice;
    }

    /**
     * @param  list<array{min: float, max: float, retail_pct: float, wholesale_pct: float}>  $rules
     */
    private static function resolveRule(float $entryTotal, array $rules): ?array
    {
        foreach ($rules as $rule) {
            if ($rule['min'] <= $entryTotal && $rule['max'] > $entryTotal) {
                return $rule;
            }
        }

        return null;
    }

    private static function multiplyAmountByEntryPrice(int $amount, float $entryPrice): float
    {
        $entryAsDecimal = number_format($entryPrice, 6, '.', '');

        return (float) bcmul((string) $amount, $entryAsDecimal, 6);
    }

    private static function bankersRound(float $value, int $decimals = 2): float
    {
        return round($value, $decimals, PHP_ROUND_HALF_EVEN);
    }
}
