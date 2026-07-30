<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PricingRule;
use App\Models\User;
use App\Models\UserPricingRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Single source of truth for deriving retail and wholesale prices from entry price.
 * Uses per-user pricing rules when present, otherwise active global pricing rules.
 * Applies bankers rounding (round half to even) to final prices.
 *
 * Optional {@see warmForUser()} preloads active rules onto this instance for catalog-style
 * multi-product pricing. Warming is instance-local (safe for Octane); never use a warmed
 * calculator across users or requests.
 */
class PriceCalculator
{
    /** @var Collection<int, PricingRule>|null */
    private ?Collection $warmedGlobalRules = null;

    /** @var Collection<int, UserPricingRule>|null */
    private ?Collection $warmedUserRules = null;

    private ?int $warmedUserId = null;

    /**
     * Preload active global rules and (when authenticated) the user's override rules.
     * Matching still uses the same priority and bracket semantics as live queries.
     */
    public function warmForUser(?User $user): self
    {
        $this->warmedGlobalRules = PricingRule::query()
            ->where('is_active', true)
            ->orderBy('priority')
            ->get();

        if ($user !== null) {
            $this->warmedUserId = (int) $user->id;
            $this->warmedUserRules = UserPricingRule::query()
                ->where('user_id', $user->id)
                ->where('is_active', true)
                ->orderBy('priority')
                ->get();
        } else {
            $this->warmedUserId = null;
            $this->warmedUserRules = null;
        }

        return $this;
    }

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
            return $this->lookupRule($entryPrice, (int) $userId);
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
            $userRule = $this->matchUserRule($entryPrice, $userId);

            if ($userRule !== null) {
                return [$userRule, true];
            }
        }

        return [$this->matchGlobalRule($entryPrice), false];
    }

    private function matchUserRule(float $entryPrice, int $userId): ?UserPricingRule
    {
        if ($this->warmedUserRules !== null && $this->warmedUserId === $userId) {
            return $this->warmedUserRules->first(
                fn (UserPricingRule $rule): bool => (float) $rule->min_price <= $entryPrice
                    && (float) $rule->max_price > $entryPrice
            );
        }

        return UserPricingRule::query()
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->where('min_price', '<=', $entryPrice)
            ->where('max_price', '>', $entryPrice)
            ->orderBy('priority')
            ->first();
    }

    private function matchGlobalRule(float $entryPrice): ?PricingRule
    {
        if ($this->warmedGlobalRules !== null) {
            return $this->warmedGlobalRules->first(
                fn (PricingRule $rule): bool => (float) $rule->min_price <= $entryPrice
                    && (float) $rule->max_price > $entryPrice
            );
        }

        return PricingRule::query()
            ->where('is_active', true)
            ->where('min_price', '<=', $entryPrice)
            ->where('max_price', '>', $entryPrice)
            ->orderBy('priority')
            ->first();
    }
}
