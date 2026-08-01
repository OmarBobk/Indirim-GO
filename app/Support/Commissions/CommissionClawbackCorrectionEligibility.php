<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\DTOs\Admin\CommissionClawbackCorrectionDecisionDTO;
use App\Enums\CommissionClawbackStatus;
use App\Enums\WalletTransactionType;
use App\Models\CommissionClawback;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;

/**
 * Server-only correction eligibility (M7.2.3).
 * Direct correction allowed with correct_commission_clawbacks (stronger than dispute).
 */
final class CommissionClawbackCorrectionEligibility
{
    public function __construct(
        private readonly CommissionClawbackWaiverArithmetic $arithmetic = new CommissionClawbackWaiverArithmetic,
        private readonly CommissionClawbackDisputeState $disputeState = new CommissionClawbackDisputeState,
    ) {}

    public function decide(CommissionClawback $clawback): CommissionClawbackCorrectionDecisionDTO
    {
        $status = $clawback->status instanceof CommissionClawbackStatus
            ? $clawback->status
            : CommissionClawbackStatus::tryFrom((string) $clawback->status);

        if ($status === CommissionClawbackStatus::Waived) {
            return $this->denied($clawback, 'messages.clawback_correction_already_waived', 'already_waived');
        }

        if ($clawback->reversal_wallet_transaction_id === null) {
            return $this->denied($clawback, 'messages.clawback_correction_requires_posted', 'unposted');
        }

        $reversal = WalletTransaction::query()->find($clawback->reversal_wallet_transaction_id);
        if ($reversal === null
            || $reversal->type !== WalletTransactionType::CommissionReversal
            || $reversal->status !== WalletTransaction::STATUS_POSTED
        ) {
            return $this->denied($clawback, 'messages.clawback_correction_missing_reversal', 'missing_reversal');
        }

        $walletUserId = Wallet::query()->whereKey($reversal->wallet_id)->value('user_id');
        if ($walletUserId === null || (int) $walletUserId !== (int) $clawback->salesperson_id) {
            return $this->denied($clawback, 'messages.clawback_correction_wrong_wallet', 'wrong_wallet');
        }

        if (! LedgerMoney::equals((string) $reversal->amount, (string) $clawback->amount)) {
            return $this->denied($clawback, 'messages.clawback_correction_amount_mismatch', 'amount_mismatch');
        }

        $remaining = $this->arithmetic->remainingCorrectable($clawback);
        if (LedgerMoney::compare($remaining, LedgerMoney::ZERO) !== 1) {
            return $this->denied($clawback, 'messages.clawback_correction_nothing_remaining', 'fully_settled');
        }

        $isFull = LedgerMoney::equals($remaining, $this->arithmetic->postedReversalAmount($clawback));

        return new CommissionClawbackCorrectionDecisionDTO(
            allowed: true,
            mode: $isFull ? 'posted_full' : 'posted_partial',
            maximumAmount: $remaining,
            remainingCorrectable: $remaining,
            safeDenialKey: '',
            status: CommissionClawbackStatus::Posted->value,
            requiresAmountInput: true,
            hasActiveDispute: $this->disputeState->hasActiveDispute($clawback),
        );
    }

    private function denied(
        CommissionClawback $clawback,
        string $key,
        string $mode,
    ): CommissionClawbackCorrectionDecisionDTO {
        return new CommissionClawbackCorrectionDecisionDTO(
            allowed: false,
            mode: $mode,
            maximumAmount: LedgerMoney::ZERO,
            remainingCorrectable: $this->arithmetic->remainingCorrectable($clawback),
            safeDenialKey: $key,
            status: $clawback->status instanceof CommissionClawbackStatus
                ? $clawback->status->value
                : (string) $clawback->status,
            requiresAmountInput: false,
            hasActiveDispute: $this->disputeState->hasActiveDispute($clawback),
        );
    }
}
