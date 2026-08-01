<?php

declare(strict_types=1);

namespace App\DTOs\Admin;

final readonly class CommissionClawbackCorrectionDecisionDTO
{
    public function __construct(
        public bool $allowed,
        public string $mode,
        public string $maximumAmount,
        public string $remainingCorrectable,
        public string $safeDenialKey,
        public string $status,
        public bool $requiresAmountInput,
        public bool $hasActiveDispute = false,
    ) {}
}
