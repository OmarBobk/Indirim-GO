<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Models\WalletTransaction;

final class WalletAdjustmentResult
{
    public function __construct(
        public readonly WalletTransaction $transaction,
        public readonly string $previousBalance,
        public readonly string $newBalance,
    ) {}
}
