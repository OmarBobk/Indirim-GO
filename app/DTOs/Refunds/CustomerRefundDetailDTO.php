<?php

declare(strict_types=1);

namespace App\DTOs\Refunds;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\CustomerRefundStatus;
use Carbon\CarbonInterface;

/**
 * Owned customer refund detail read result.
 *
 * @param  list<array{key: string, label_key: string, occurred_at: string|null}>  $timeline
 */
final readonly class CustomerRefundDetailDTO
{
    /**
     * @param  list<array{key: string, label_key: string, occurred_at: string|null}>  $timeline
     */
    public function __construct(
        public string $publicReference,
        public CustomerRefundStatus $status,
        public string $amount,
        public string $currency,
        public CarbonInterface $requestedAt,
        public ?CarbonInterface $reviewedAt,
        public ?CarbonInterface $postedAt,
        public bool $moneyMoved,
        public bool $canRecover,
        public bool $isIntegrityAnomaly,
        public ?string $customerSafeReason,
        public ?string $orderNumber,
        public ?string $productLabel,
        public ?string $quantityContextKey,
        public ?int $orderItemQuantity,
        public ?string $fulfillmentStatusLabelKey,
        public array $timeline,
        public FinancialDestinationDTO $destination,
        public ?FinancialDestinationDTO $orderDestination,
        public ?FinancialDestinationDTO $ledgerDestination,
        public ?FinancialDestinationDTO $recoveryDestination,
    ) {}
}
