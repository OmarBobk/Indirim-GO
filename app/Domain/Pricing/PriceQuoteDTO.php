<?php

declare(strict_types=1);

namespace App\Domain\Pricing;

final readonly class PriceQuoteDTO
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        public string $amountMode,
        public float $basePrice,
        public float $discountAmount,
        public float $finalPrice,
        public float $finalTotal,
        public float $unitPrice,
        public int $quantity,
        public ?int $requestedAmount,
        public ?string $tierName,
        public array $meta = [],
        public string $basePriceDecimal = '0.00',
        public string $discountAmountDecimal = '0.00',
        public string $finalPriceDecimal = '0.00',
        public string $finalTotalDecimal = '0.00',
        public string $unitPriceDecimal = '0.00',
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'amount_mode' => $this->amountMode,
            'base_price' => $this->basePrice,
            'discount_amount' => $this->discountAmount,
            'final_price' => $this->finalPrice,
            'final_total' => $this->finalTotal,
            'unit_price' => $this->unitPrice,
            'quantity' => $this->quantity,
            'requested_amount' => $this->requestedAmount,
            'tier_name' => $this->tierName,
            'meta' => $this->meta,
        ];
    }
}
