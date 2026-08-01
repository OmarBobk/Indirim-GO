<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Enums\CommissionClawbackDecisionStatus;
use App\Enums\CommissionClawbackDecisionType;
use App\Enums\WalletTransactionType;
use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;
use Illuminate\Support\Collection;

/**
 * Shared cumulative waiver + correction arithmetic (M7.2.2 / M7.2.3).
 * net_collected = posted_reversal − waiver_credits − correction_credits
 */
final class CommissionClawbackWaiverArithmetic
{
    public function postedReversalAmount(CommissionClawback $clawback): string
    {
        $reversal = $clawback->reversalWalletTransaction;
        if ($reversal === null && $clawback->reversal_wallet_transaction_id !== null) {
            $reversal = WalletTransaction::query()->find($clawback->reversal_wallet_transaction_id);
        }

        if ($reversal === null
            || $reversal->type !== WalletTransactionType::CommissionReversal
            || $reversal->status !== WalletTransaction::STATUS_POSTED
        ) {
            return LedgerMoney::ZERO;
        }

        return LedgerMoney::normalize((string) $reversal->amount);
    }

    public function postedWaiverCredits(CommissionClawback $clawback): string
    {
        return $this->sumPostedCreditsOfType(
            $clawback,
            CommissionClawbackDecisionType::Waiver,
            WalletTransactionType::CommissionClawbackWaiver,
        );
    }

    public function postedCorrectionCredits(CommissionClawback $clawback): string
    {
        return $this->sumPostedCreditsOfType(
            $clawback,
            CommissionClawbackDecisionType::Correction,
            WalletTransactionType::CommissionReversalCorrection,
        );
    }

    /**
     * Remaining amount that may still be waived or corrected (shared cap).
     */
    public function remainingRecoverable(CommissionClawback $clawback): string
    {
        $reversal = $this->postedReversalAmount($clawback);
        $settled = LedgerMoney::add(
            $this->postedWaiverCredits($clawback),
            $this->postedCorrectionCredits($clawback),
        );

        if (LedgerMoney::compare($settled, $reversal) === 1) {
            return LedgerMoney::ZERO;
        }

        return LedgerMoney::sub($reversal, $settled);
    }

    public function remainingWaivable(CommissionClawback $clawback): string
    {
        return $this->remainingRecoverable($clawback);
    }

    public function remainingCorrectable(CommissionClawback $clawback): string
    {
        return $this->remainingRecoverable($clawback);
    }

    public function netCollected(CommissionClawback $clawback): string
    {
        return $this->remainingRecoverable($clawback);
    }

    public function hasRecordedUnpostedFullWaiver(CommissionClawback $clawback): bool
    {
        return CommissionClawbackDecision::query()
            ->where('commission_clawback_id', $clawback->id)
            ->where('type', CommissionClawbackDecisionType::Waiver)
            ->where('status', CommissionClawbackDecisionStatus::Recorded)
            ->exists();
    }

    /**
     * @return Collection<int, CommissionClawbackDecision>
     */
    public function waiverDecisions(CommissionClawback $clawback): Collection
    {
        return CommissionClawbackDecision::query()
            ->where('commission_clawback_id', $clawback->id)
            ->where('type', CommissionClawbackDecisionType::Waiver)
            ->with('relatedWalletTransaction:id,public_ref,amount,status,type')
            ->orderBy('decided_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, CommissionClawbackDecision>
     */
    public function correctionDecisions(CommissionClawback $clawback): Collection
    {
        return CommissionClawbackDecision::query()
            ->where('commission_clawback_id', $clawback->id)
            ->where('type', CommissionClawbackDecisionType::Correction)
            ->with('relatedWalletTransaction:id,public_ref,amount,status,type')
            ->orderBy('decided_at')
            ->orderBy('id')
            ->get();
    }

    public static function walletIdempotencyKey(int $decisionId): string
    {
        return 'commission_clawback_waiver:'.$decisionId;
    }

    public static function correctionWalletIdempotencyKey(int $decisionId): string
    {
        return 'commission_reversal_correction:'.$decisionId;
    }

    private function sumPostedCreditsOfType(
        CommissionClawback $clawback,
        CommissionClawbackDecisionType $decisionType,
        WalletTransactionType $txType,
    ): string {
        $ids = CommissionClawbackDecision::query()
            ->where('commission_clawback_id', $clawback->id)
            ->where('type', $decisionType)
            ->where('status', CommissionClawbackDecisionStatus::Posted)
            ->whereNotNull('related_wallet_transaction_id')
            ->pluck('related_wallet_transaction_id');

        if ($ids->isEmpty()) {
            return LedgerMoney::ZERO;
        }

        $sum = WalletTransaction::query()
            ->whereIn('id', $ids->all())
            ->where('type', $txType)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->sum('amount');

        return LedgerMoney::normalize((string) ($sum ?: '0'));
    }
}
