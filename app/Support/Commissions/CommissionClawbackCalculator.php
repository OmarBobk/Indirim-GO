<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Enums\WalletTransactionType;
use App\Models\Commission;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;

/**
 * Full per-fulfillment reversal math (M7.1).
 * remaining = original commission − posted reversals for commission+refund scope.
 */
final class CommissionClawbackCalculator
{
    /**
     * @return array{remaining: string, posted_reversals: string, target: string}
     */
    public function remainingForObligation(
        Commission $commission,
        int $refundWalletTransactionId,
        ?WalletTransaction $existingReversal = null,
    ): array {
        $target = LedgerMoney::normalizePositive((string) $commission->commission_amount);
        $posted = $this->postedReversalTotal($commission->id, $refundWalletTransactionId, $existingReversal);

        if (LedgerMoney::compare($posted, $target) === 1) {
            return [
                'remaining' => LedgerMoney::ZERO,
                'posted_reversals' => $posted,
                'target' => $target,
                'over_reversed' => true,
            ];
        }

        return [
            'remaining' => LedgerMoney::sub($target, $posted),
            'posted_reversals' => $posted,
            'target' => $target,
            'over_reversed' => false,
        ];
    }

    public function postedReversalTotal(
        int $commissionId,
        int $refundWalletTransactionId,
        ?WalletTransaction $existingReversal = null,
    ): string {
        $query = WalletTransaction::query()
            ->where('type', WalletTransactionType::CommissionReversal)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->where('reference_type', Commission::class)
            ->where('reference_id', $commissionId)
            ->where('idempotency_key', CommissionClawbackPolicy::reversalIdempotencyKey(
                $commissionId,
                $refundWalletTransactionId,
            ));

        if ($existingReversal !== null) {
            $query->whereKey($existingReversal->id);
        }

        $sum = $query->sum('amount');

        return LedgerMoney::normalize((string) ($sum ?: '0'));
    }
}
