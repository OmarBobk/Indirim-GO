<?php

declare(strict_types=1);

namespace App\DTOs\Admin;

/**
 * Server-side clawback waiver eligibility (M7.2.2).
 */
final readonly class CommissionClawbackWaiverDecisionDTO
{
    public function __construct(
        public bool $allowed,
        public string $mode,
        public string $maximumAmount,
        public string $remainingWaivable,
        public string $safeDenialKey,
        public string $status,
        public bool $requiresAmountInput,
    ) {}
}
