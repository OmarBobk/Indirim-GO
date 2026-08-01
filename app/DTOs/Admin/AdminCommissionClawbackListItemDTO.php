<?php

declare(strict_types=1);

namespace App\DTOs\Admin;

/**
 * One admin clawback inbox row (M7.2.1).
 */
final readonly class AdminCommissionClawbackListItemDTO
{
    public function __construct(
        public string $publicRef,
        public string $status,
        public string $amount,
        public string $currency,
        public ?string $salespersonName,
        public ?string $salespersonEmail,
        public int $salespersonId,
        public ?string $orderNumber,
        public ?string $refundPublicRef,
        public ?string $originalCreditPublicRef,
        public ?string $reversalPublicRef,
        public ?string $failureCode,
        public string $failureCategory,
        public bool $isRetryable,
        public bool $isStale,
        public bool $isActionRequired,
        public bool $hasOutstandingDebt,
        public bool $debtRecovered,
        public int $policyVersion,
        public string $createdAtIso,
        public ?string $attemptedAtIso,
        public ?string $postedAtIso,
        public ?string $lastRetryAtIso,
        public bool $isPartiallyWaived = false,
        public bool $isDisputed = false,
        public bool $isPartiallyCorrected = false,
        public bool $isFullyCorrected = false,
        public bool $isCorrectionAvailable = false,
        public bool $isNetCollectedZero = false,
    ) {}
}
