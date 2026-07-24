<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PricingRule;
use App\Models\User;
use App\Models\UserPricingRule;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Single source of truth for deriving retail and wholesale prices from entry price.
 * Uses per-user pricing rules when present, otherwise active global pricing rules.
 * Applies bankers rounding (round half to even) to final prices.
 */
class PriceCalculator
{
    /**
     * @return array{retail_price: float, wholesale_price: float, uses_user_pricing: bool}
     */
    public function calculate(float $entryPrice, int $roundingScale = 2, ?User $user = null): array
    {
        [$rule, $usesUserPricing] = $this->resolveRule($entryPrice, $user);

        if ($rule === null) {
            throw new InvalidArgumentException(
                "No active pricing rule matches entry price [{$entryPrice}]. ".
                'Ensure a default rule covers all entry price ranges.'
            );
        }

        $roundingScale = max(0, min(8, $roundingScale));

        $retailPrice = $this->applyBankersRounding(
            $entryPrice * (1 + (float) $rule->retail_percentage / 100),
            $roundingScale
        );
        $wholesalePrice = $this->applyBankersRounding(
            $entryPrice * (1 + (float) $rule->wholesale_percentage / 100),
            $roundingScale
        );

        return [
            'retail_price' => $retailPrice,
            'wholesale_price' => $wholesalePrice,
            'uses_user_pricing' => $usesUserPricing,
        ];
    }

    private function applyBankersRounding(float $value, int $decimals = 2): float
    {
        return round($value, $decimals, PHP_ROUND_HALF_EVEN);
    }

    /**
     * @return array{0: PricingRule|UserPricingRule|null, 1: bool}
     */
    private function resolveRule(float $entryPrice, ?User $user): array
    {
        $userId = $user?->id;

        if ($userId !== null) {
            return $this->lookupRule($entryPrice, $userId);
        }

        $entryKey = number_format($entryPrice, 6, '.', '');
        $cacheKey = "pricing_rule_{$entryKey}";

        return Cache::remember($cacheKey, 60, fn (): array => $this->lookupRule($entryPrice, null));
    }

    /**
     * @return array{0: PricingRule|UserPricingRule|null, 1: bool}
     */
    private function lookupRule(float $entryPrice, ?int $userId): array
    {
        if ($userId !== null) {
            $userRule = UserPricingRule::query()
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->where('min_price', '<=', $entryPrice)
                ->where('max_price', '>', $entryPrice)
                ->orderBy('priority')
                ->first();

            if ($userRule !== null) {
                return [$userRule, true];
            }
        }

        $globalRule = PricingRule::query()
            ->where('is_active', true)
            ->where('min_price', '<=', $entryPrice)
            ->where('max_price', '>', $entryPrice)
            ->orderBy('priority')
            ->first();

        return [$globalRule, false];
    }
}
