<?php

declare(strict_types=1);

namespace App\DTOs\Topups;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\TopupRequestStatus;
use Carbon\CarbonInterface;

/**
 * Customer top-up workflow list row. Presentation-free.
 */
final readonly class CustomerTopupRequestDTO
{
    public function __construct(
        public string $stableKey,
        public string $publicReference,
        public TopupRequestStatus $status,
        public string $amount,
        public string $currency,
        public CarbonInterface $submittedAt,
        public CarbonInterface $updatedAt,
        public ?CarbonInterface $approvedAt,
        public ?string $paymentMethodName,
        public bool $hasProof,
        public bool $moneyMoved,
        public bool $canRetry,
        public bool $isIntegrityAnomaly,
        public ?string $postedTransactionPublicRef,
        public ?string $customerSafeReason,
        public FinancialDestinationDTO $destination,
    ) {}
}
