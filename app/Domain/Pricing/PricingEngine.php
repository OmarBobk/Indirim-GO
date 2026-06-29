<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Enums\ProductAmountMode;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerPriceService;

final class PricingEngine
{
    public function __construct(
        private readonly CustomerPriceService $priceService,
        private readonly CustomAmountValidator $customAmountValidator,
    ) {}

    public function quote(Product $product, int $quantity = 1, ?int $amount = null, ?User $user = null): PriceQuoteDTO
    {
        $amountMode = $product->amount_mode ?? ProductAmountMode::Fixed;

        if ($amountMode === ProductAmountMode::Custom) {
            $requestedAmount = $this->customAmountValidator->validate($product, $amount);
            $prices = $this->quoteCustom($product, $requestedAmount, $user);
            $final = (float) $prices['final_price'];

            return new PriceQuoteDTO(
                amountMode: ProductAmountMode::Custom->value,
                basePrice: (float) $prices['base_price'],
                discountAmount: (float) $prices['discount_amount'],
                finalPrice: $final,
                finalTotal: $final,
                unitPrice: $requestedAmount > 0
                    ? (float) bcdiv(number_format($final, 8, '.', ''), (string) $requestedAmount, 8)
                    : $final,
                quantity: 1,
                requestedAmount: $requestedAmount,
                tierName: $prices['tier_name'] ?? null,
                meta: (array) ($prices['meta'] ?? []),
            );
        }

        $normalizedQuantity = max(1, $quantity);
        $prices = $user !== null
            ? $this->priceService->finalPriceForQuantity($product, $normalizedQuantity, $user)
            : $this->quoteFixedGuest($product, $normalizedQuantity);

        return new PriceQuoteDTO(
            amountMode: ProductAmountMode::Fixed->value,
            basePrice: (float) $prices['base_price'],
            discountAmount: (float) $prices['discount_amount'],
            finalPrice: (float) $prices['unit_price'],
            finalTotal: (float) $prices['final_total'],
            unitPrice: (float) $prices['unit_price'],
            quantity: $normalizedQuantity,
            requestedAmount: null,
            tierName: $prices['tier_name'] ?? null,
            meta: (array) ($prices['meta'] ?? []),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteCustom(Product $product, int $amount, ?User $user): array
    {
        if ($user !== null) {
            return $this->priceService->finalPriceForAmount($product, $amount, $user);
        }

        $entryPrice = (float) $product->entry_price;
        $computedEntryTotal = (float) bcmul(
            (string) $amount,
            number_format($entryPrice, 6, '.', ''),
            6
        );
        $pricingProduct = clone $product;
        $pricingProduct->setAttribute('entry_price', $computedEntryTotal);

        return $this->priceService->priceFor($pricingProduct, null);
    }

    /**
     * @return array<string, mixed>
     */
    private function quoteFixedGuest(Product $product, int $quantity): array
    {
        $entryPrice = $product->entry_price !== null ? (float) $product->entry_price : null;

        if ($entryPrice === null || $entryPrice <= 0) {
            $unit = $this->priceService->priceFor($product, null);

            return [
                ...$unit,
                'unit_price' => (float) $unit['final_price'],
                'final_total' => round((float) $unit['final_price'] * $quantity, 2),
            ];
        }

        $computedEntryTotal = (float) bcmul(
            (string) $quantity,
            number_format($entryPrice, 6, '.', ''),
            6
        );
        $pricingProduct = clone $product;
        $pricingProduct->setAttribute('entry_price', $computedEntryTotal);
        $line = $this->priceService->priceFor($pricingProduct, null);

        return [
            ...$line,
            'unit_price' => $quantity > 0
                ? (float) bcdiv(number_format((float) $line['final_price'], 8, '.', ''), (string) $quantity, 8)
                : (float) $line['final_price'],
            'final_total' => (float) $line['final_price'],
        ];
    }
}
