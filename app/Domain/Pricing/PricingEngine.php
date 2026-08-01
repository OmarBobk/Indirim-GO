<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

use App\Enums\ProductAmountMode;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerPriceService;
use App\Support\LedgerMoney;

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
            $finalDecimal = $this->ledgerDecimal($prices['final_price']);
            // Keep per-unit rate at BCMath scale 8 for custom amounts (Buy Now / web).
            $unitDecimal = $requestedAmount > 0
                ? bcdiv($finalDecimal, (string) $requestedAmount, 8)
                : $finalDecimal;

            return new PriceQuoteDTO(
                amountMode: ProductAmountMode::Custom->value,
                basePrice: (float) $prices['base_price'],
                discountAmount: (float) $prices['discount_amount'],
                finalPrice: (float) $finalDecimal,
                finalTotal: (float) $finalDecimal,
                unitPrice: (float) $unitDecimal,
                quantity: 1,
                requestedAmount: $requestedAmount,
                tierName: $prices['tier_name'] ?? null,
                meta: (array) ($prices['meta'] ?? []),
                basePriceDecimal: $this->ledgerDecimal($prices['base_price']),
                discountAmountDecimal: $this->ledgerDecimal($prices['discount_amount']),
                finalPriceDecimal: $finalDecimal,
                finalTotalDecimal: $finalDecimal,
                unitPriceDecimal: $unitDecimal,
            );
        }

        $normalizedQuantity = max(1, $quantity);
        $prices = $user !== null
            ? $this->priceService->finalPriceForQuantity($product, $normalizedQuantity, $user)
            : $this->quoteFixedGuest($product, $normalizedQuantity);

        $finalTotalDecimal = $this->ledgerDecimal($prices['final_total']);
        $unitPriceDecimal = $this->unitPriceDecimal($finalTotalDecimal, $normalizedQuantity);

        return new PriceQuoteDTO(
            amountMode: ProductAmountMode::Fixed->value,
            basePrice: (float) $prices['base_price'],
            discountAmount: (float) $prices['discount_amount'],
            finalPrice: (float) $unitPriceDecimal,
            finalTotal: (float) $finalTotalDecimal,
            unitPrice: (float) $unitPriceDecimal,
            quantity: $normalizedQuantity,
            requestedAmount: null,
            tierName: $prices['tier_name'] ?? null,
            meta: (array) ($prices['meta'] ?? []),
            basePriceDecimal: $this->ledgerDecimal($prices['base_price']),
            discountAmountDecimal: $this->ledgerDecimal($prices['discount_amount']),
            finalPriceDecimal: $unitPriceDecimal,
            finalTotalDecimal: $finalTotalDecimal,
            unitPriceDecimal: $unitPriceDecimal,
        );
    }

    /**
     * Convert pricing-service numeric money onto the ledger decimal-string path
     * without sprintf('%.2F', float) binary formatting.
     */
    private function ledgerDecimal(float|int|string $amount): string
    {
        if (is_string($amount)) {
            return LedgerMoney::normalize($amount);
        }

        // CustomerPriceService already banker's-rounds to 2dp; bridge via integer cents.
        $cents = (int) round(((float) $amount) * 100, 0, PHP_ROUND_HALF_EVEN);
        $sign = $cents < 0 ? '-' : '';
        $absolute = abs($cents);

        return LedgerMoney::normalize(sprintf('%s%d.%02d', $sign, intdiv($absolute, 100), $absolute % 100));
    }

    private function unitPriceDecimal(string $finalTotalDecimal, int $quantity): string
    {
        if ($quantity <= 0) {
            return $finalTotalDecimal;
        }

        // Prefer dividing the authoritative line total so unit/line stay BCMath-consistent.
        return bcdiv($finalTotalDecimal, (string) $quantity, 8);
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
