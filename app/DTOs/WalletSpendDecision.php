<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\WalletSpendFailureReason;

final class WalletSpendDecision
{
    public function __construct(
        public readonly bool $allowed,
        public readonly string $availableToSpend,
        public readonly string $remainingCredit,
        public readonly string $effectiveCreditLimit,
        public readonly ?WalletSpendFailureReason $failureReason = null,
    ) {}

    public function isDenied(): bool
    {
        return ! $this->allowed;
    }
}
