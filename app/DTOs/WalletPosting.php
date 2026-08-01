<?php

declare(strict_types=1);

namespace App\DTOs;

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Wallet;
use App\Models\WalletTransaction;

/**
 * Mechanical posting input for WalletLedger. Workflow Actions own auth and source transitions.
 */
final class WalletPosting
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public readonly Wallet $wallet,
        public readonly WalletTransactionType $type,
        public readonly WalletTransactionDirection $direction,
        public readonly string $amount,
        public readonly string $idempotencyKey,
        public readonly ?array $meta = null,
        public readonly ?string $referenceType = null,
        public readonly ?int $referenceId = null,
        public readonly ?WalletTransaction $pendingTransaction = null,
        /**
         * Debit floor after lock. Null ⇒ derive from Wallet::minimumAllowedBalance().
         * Credits ignore this value.
         */
        public readonly ?string $minimumAllowedBalance = null,
        /**
         * Narrow M7.1 override: only CommissionReversal may authorise balance below the wallet floor.
         * Must never be set from browser input or generic adjustment/purchase paths.
         */
        public readonly bool $allowClawbackDebt = false,
    ) {}
}
