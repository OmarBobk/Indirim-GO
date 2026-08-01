<?php

declare(strict_types=1);

namespace App\DTOs\Earnings;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\CommissionStatus;
use Carbon\CarbonInterface;

/**
 * One salesperson-owned commission row (M6.6 / M7.1).
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
        public bool $isFullyReversed = false,
        public ?string $clawbackPublicRef = null,
        public ?string $reversalWalletTransactionPublicRef = null,
        public ?string $reversedAmount = null,
        public ?FinancialDestinationDTO $reversalTransactionDestination = null,
        public bool $clawbackNeedsReview = false,
        public bool $isFullyWaived = false,
        public bool $isPartiallyWaived = false,
        public ?string $waivedAmount = null,
        public ?string $waiverWalletTransactionPublicRef = null,
        public ?FinancialDestinationDTO $waiverTransactionDestination = null,
        public bool $isPartiallyCorrected = false,
        public bool $isFullyCorrected = false,
        public bool $isUnderDisputeReview = false,
        public ?string $correctedAmount = null,
        public ?string $correctionWalletTransactionPublicRef = null,
        public ?FinancialDestinationDTO $correctionTransactionDestination = null,
        public ?string $netCommissionEffect = null,
    ) {}
}
