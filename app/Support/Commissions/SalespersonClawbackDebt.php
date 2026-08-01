<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;

/**
 * Salesperson clawback debt read helpers (M7.1).
 * Negative balance alone is insufficient — require posted commission_reversal evidence.
 */
final class SalespersonClawbackDebt
{
    public function hasOutstandingDebt(Wallet $wallet): bool
    {
        if (LedgerMoney::compare((string) $wallet->balance, LedgerMoney::ZERO) !== -1) {
            return false;
        }

        return $this->hasPostedReversalEvidence($wallet);
    }

    public function amount(Wallet $wallet): string
    {
        if (! $this->hasOutstandingDebt($wallet)) {
            return LedgerMoney::ZERO;
        }

        return $wallet->outstandingDebt();
    }

    public function blocksPayoutRequests(User $salesperson, ?Wallet $wallet = null): bool
    {
        $wallet ??= Wallet::forUser($salesperson);

        return $this->hasOutstandingDebt($wallet);
    }

    public function hasPostedReversalEvidence(Wallet $wallet): bool
    {
        return WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransactionType::CommissionReversal)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->exists();
    }
}
