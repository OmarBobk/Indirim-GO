<?php

declare(strict_types=1);

namespace App\DTOs\Earnings;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\CommissionStatus;
use Carbon\CarbonInterface;

/**
 * One salesperson-owned commission row (M6.6).
 */
final readonly class CommissionDTO
{
    public function __construct(
        public string $stableKey,
        public CommissionStatus $status,
        public string $amount,
        public string $currency,
        public string $ratePercent,
        public ?string $orderNumber,
        public ?string $orderTotal,
        public ?string $customerSafeLabel,
        public CarbonInterface $createdAt,
        public ?CarbonInterface $creditedAt,
        public bool $isEligible,
        public bool $isIntegrityAnomaly,
        public ?string $walletTransactionPublicRef,
        public ?string $actorNextKey,
        public ?FinancialDestinationDTO $transactionDestination,
        public ?FinancialDestinationDTO $orderDestination,
    ) {}
}
