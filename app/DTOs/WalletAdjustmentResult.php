<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\Wallet;
use App\Models\WalletTransaction;

final class WalletAdjustmentResult
{
    public function __construct(
        public readonly WalletTransaction $transaction,
        public readonly string $previousBalance,
        public readonly string $newBalance,
        public readonly Wallet $wallet,
        public readonly bool $wasReplayed = false,
        public readonly bool $wasPromoted = false,
    ) {}

    public function balanceBefore(): string
    {
        return $this->previousBalance;
    }

    public function balanceAfter(): string
    {
        return $this->newBalance;
    }
}
