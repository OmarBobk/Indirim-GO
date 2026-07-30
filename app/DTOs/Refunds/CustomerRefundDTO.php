<?php

declare(strict_types=1);

namespace App\DTOs\Refunds;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\CustomerRefundStatus;
use Carbon\CarbonInterface;

/**
 * Customer refund workflow list row. Presentation-free.
 */
final readonly class CustomerRefundDTO
{
    public function __construct(
        public string $stableKey,
        public string $publicReference,
        public CustomerRefundStatus $status,
        public string $amount,
        public string $currency,
        public CarbonInterface $requestedAt,
        public ?CarbonInterface $postedAt,
        public ?string $orderNumber,
        public ?string $productLabel,
        public bool $moneyMoved,
        public bool $canRecover,
        public bool $isIntegrityAnomaly,
        public ?string $customerSafeReason,
        public FinancialDestinationDTO $destination,
    ) {}
}
