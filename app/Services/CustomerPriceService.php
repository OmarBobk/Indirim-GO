<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\LoyaltyTierConfig;
use App\Models\Product;
use App\Models\User;
use InvalidArgumentException;

/**
 * Single source of customer-facing price including loyalty discount.
 * Uses PriceCalculator for base retail/wholesale; salesperson role gets wholesale, others get retail.
 * Applies tier discount on top.
 */
class CustomerPriceService
{
    public function __construct(
        private readonly PriceCalculator $priceCalculator
    ) {}

    /**
     * Resolve customer price for a product or raw entry price.
     *
     * @return array{base_price: float, discount_amount: float, final_price: float, tier_name: string|null, meta: array{uses_user_pricing: bool, is_floor_applied: bool}}
     */
    public function priceFor(Product|float $productOrEntryPrice, ?User $user = null): array
    {
        $prices = $this->resolveRetailAndWholesale($productOrEntryPrice, $user);
        $useWholesale = $user !== null && $user->hasRole('salesperson');
        $basePrice = $useWholesale ? $prices['wholesale_price'] : $prices['retail_price'];
        $usesUserPricing = $prices['uses_user_pricing'];
        $tierConfig = $user !== null ? $this->tierConfigForUser($user) : null;
        $discountPercent = $tierConfig !== null ? (float) $tierConfig->discount_percentage : 0.0;
        $discountAmount = $this->round($basePrice * $discountPercent / 100);
        $finalPrice = $this->round($basePrice - $discountAmount);
        $tierName = $tierConfig?->name;
        $isFloorApplied = false;

        if ($productOrEntryPrice instanceof Product && $productOrEntryPrice->entry_price !== null && $finalPrice < (float) $productOrEntryPrice->entry_price) {
            $finalPrice = $this->round((float) $productOrEntryPrice->entry_price);
            $discountAmount = $this->round($basePrice - $finalPrice);
            $isFloorApplied = true;
        }

        return [
            'base_price' => $basePrice,
            'discount_amount' => $discountAmount,
            'final_price' => $finalPrice,
            'tier_name' => $tierName,
            'meta' => [
                'uses_user_pricing' => $usesUserPricing,
                'is_floor_applied' => $isFloorApplied,
            ],
        ];
    }

    /**
     * Final price only (for order creation and simple display).
     */
    public function finalPrice(Product|float $productOrEntryPrice, ?User $user = null): float
    {
        return $this->priceFor($productOrEntryPrice, $user)['final_price'];
    }

    /**
     * @return array{base_price: float, discount_amount: float, final_price: float, tier_name: string|null, meta: array{uses_user_pricing: bool, is_floor_applied: bool}}
     */
    public function finalPriceForAmount(Product $product, int $amount, User $user): array
    {
        return $this->priceForComputedTotal($product, $amount, $user);
    }

    /**
     * @return array{
     *   base_price: float,
     *   discount_amount: float,
     *   final_price: float,
     *   final_total: float,
     *   unit_price: float,
     *   tier_name: string|null,
     *   meta: array{uses_user_pricing: bool, is_floor_applied: bool}
     * }
     */
    public function finalPriceForQuantity(Product $product, int $quantity, User $user): array
    {
        if ($quantity <= 0) {
            throw new InvalidArgumentException('Quantity must be greater than zero.');
        }

        $prices = $this->priceForComputedTotal($product, $quantity, $user);
        $finalTotal = (float) $prices['final_price'];

        return [
            ...$prices,
            'final_total' => $finalTotal,
            'unit_price' => $this->divideTotalByQuantity($finalTotal, $quantity),
        ];
    }

    /**
     * @return array{base_price: float, discount_amount: float, final_price: float, tier_name: string|null, meta: array{uses_user_pricing: bool, is_floor_applied: bool}}
     */
    private function priceForComputedTotal(Product $product, int $multiplier, User $user): array
    {
        $entryPrice = $product->entry_price !== null
            ? (float) $product->entry_price
            : null;

        if ($entryPrice === null || $entryPrice <= 0) {
            throw new InvalidArgumentException('Invalid entry price for product.');
        }

        $computedEntryTotal = $this->multiplyAmountByEntryPrice($multiplier, $entryPrice);
        $pricingProduct = clone $product;
        $pricingProduct->setAttribute('entry_price', $computedEntryTotal);

        return $this->priceFor($pricingProduct, $user);
    }

    /**
     * @return array{retail_price: float, wholesale_price: float, uses_user_pricing: bool}
     */
    private function resolveRetailAndWholesale(Product|float $productOrEntryPrice, ?User $user = null): array
    {
        if ($productOrEntryPrice instanceof Product) {
            $entryPrice = $productOrEntryPrice->entry_price !== null
                ? (float) $productOrEntryPrice->entry_price
                : null;
            if ($entryPrice !== null) {
                return $this->priceCalculator->calculate($entryPrice, 2, $user);
            }

            return [
                'retail_price' => (float) $productOrEntryPrice->retail_price,
                'wholesale_price' => (float) $productOrEntryPrice->wholesale_price,
                'uses_user_pricing' => false,
            ];
        }

        return $this->priceCalculator->calculate((float) $productOrEntryPrice, 2, $user);
    }

    private function tierConfigForUser(User $user): ?LoyaltyTierConfig
    {
        $role = $user->loyaltyRole();
        if ($role === null) {
            return null;
        }
        $tierName = $user->loyalty_tier?->value ?? 'bronze';

        return LoyaltyTierConfig::query()->forRole($role)->where('name', $tierName)->first();
    }

    private function round(float $value): float
    {
        return round($value, 2, PHP_ROUND_HALF_EVEN);
    }

    private function multiplyAmountByEntryPrice(int $amount, float $entryPrice): float
    {
        $entryAsDecimal = number_format($entryPrice, 6, '.', '');
        $computed = bcmul((string) $amount, $entryAsDecimal, 6);

        return (float) $computed;
    }

    private function divideTotalByQuantity(float $finalTotal, int $quantity): float
    {
        $totalAsDecimal = number_format($finalTotal, 8, '.', '');
        $computed = bcdiv($totalAsDecimal, (string) $quantity, 8);

        return (float) $computed;
    }
}
