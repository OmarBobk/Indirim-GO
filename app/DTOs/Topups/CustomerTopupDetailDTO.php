<?php

declare(strict_types=1);

namespace App\DTOs\Topups;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\TopupRequestStatus;
use Carbon\CarbonInterface;

/**
 * Owned customer top-up detail read result.
 *
 * @param  list<array{key: string, label_key: string, occurred_at: string|null}>  $timeline
 */
final readonly class CustomerTopupDetailDTO
{
    /**
     * @param  list<array{key: string, label_key: string, occurred_at: string|null}>  $timeline
     */
    public function __construct(
        public string $publicReference,
        public TopupRequestStatus $status,
        public string $amount,
        public string $currency,
        public ?string $paymentMethodName,
        public ?string $paymentInstructionsPlain,
        public CarbonInterface $submittedAt,
        public ?CarbonInterface $reviewedAt,
        public ?CarbonInterface $creditedAt,
        public bool $moneyMoved,
        public bool $hasProof,
        public ?int $proofId,
        public bool $canRetry,
        public bool $isIntegrityAnomaly,
        public ?string $customerSafeReason,
        public ?string $postedTransactionPublicRef,
        public array $timeline,
        public FinancialDestinationDTO $destination,
        public ?FinancialDestinationDTO $ledgerDestination,
        public ?FinancialDestinationDTO $retryDestination,
        public ?FinancialDestinationDTO $proofDestination,
    ) {}
}
