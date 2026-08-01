<?php

declare(strict_types=1);

namespace App\DTOs\Admin;

/**
 * One historical commission exposure report row (M7.2.4).
 */
final readonly class HistoricalCommissionExposureItemDTO
{
    public function __construct(
        public int $commissionId,
        public int $salespersonId,
        public ?string $salespersonName,
        public string $commissionAmount,
        public string $currency,
        public ?string $orderNumber,
        public ?int $fulfillmentId,
        public ?string $creditPublicRef,
        public ?string $refundPublicRef,
        public int $refundWalletTransactionId,
        public ?string $creditedAtIso,
        public ?string $refundedAtIso,
        public string $exposureAmount,
        public string $confidence,
        public bool $isReviewed,
        public ?string $reviewOutcome,
        public ?string $reviewedAtIso,
        public ?string $reviewedByName,
    ) {}
}
