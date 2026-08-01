<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\DTOs\Admin\CommissionClawbackWaiverDecisionDTO;
use App\Enums\CommissionClawbackStatus;
use App\Enums\WalletTransactionType;
use App\Models\CommissionClawback;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;

/**
 * Server-only waiver eligibility for commission clawbacks (M7.2.2).
 */
final class CommissionClawbackWaiverEligibility
{
    public function __construct(
        private readonly CommissionClawbackWaiverArithmetic $arithmetic = new CommissionClawbackWaiverArithmetic,
        private readonly CommissionClawbackRetryEligibility $retryEligibility = new CommissionClawbackRetryEligibility,
    ) {}

    public function decide(CommissionClawback $clawback): CommissionClawbackWaiverDecisionDTO
    {
        $status = $clawback->status instanceof CommissionClawbackStatus
            ? $clawback->status
            : CommissionClawbackStatus::tryFrom((string) $clawback->status);

        if ($status === CommissionClawbackStatus::Waived
            || $this->arithmetic->hasRecordedUnpostedFullWaiver($clawback)
        ) {
            return $this->denied($clawback, 'messages.clawback_waiver_already_waived', 'already_waived');
        }

        if ($status === CommissionClawbackStatus::Processing
            && ! $this->retryEligibility->isStaleProcessing($clawback)
        ) {
            return $this->denied($clawback, 'messages.clawback_waiver_still_processing', 'still_processing');
        }

        $linkedReversal = $clawback->reversal_wallet_transaction_id !== null;
        $matchingReversal = $this->findMatchingPostedReversal($clawback);
        $isPostedPath = $linkedReversal || $matchingReversal !== null || $status === CommissionClawbackStatus::Posted;

        if ($isPostedPath) {
            return $this->decidePosted($clawback, $matchingReversal);
        }

        return $this->decideUnposted($clawback, $status);
    }

    private function decideUnposted(
        CommissionClawback $clawback,
        ?CommissionClawbackStatus $status,
    ): CommissionClawbackWaiverDecisionDTO {
        if (! in_array($status, [
            CommissionClawbackStatus::Pending,
            CommissionClawbackStatus::NeedsReview,
            CommissionClawbackStatus::Failed,
            CommissionClawbackStatus::Processing,
        ], true)) {
            return $this->denied($clawback, 'messages.clawback_waiver_unavailable', 'invalid_status');
        }

        if ($this->findMatchingPostedReversal($clawback) !== null) {
            return $this->decidePosted($clawback, $this->findMatchingPostedReversal($clawback));
        }

        $amount = LedgerMoney::normalize((string) $clawback->amount);
        if (LedgerMoney::compare($amount, LedgerMoney::ZERO) !== 1) {
            return $this->denied($clawback, 'messages.clawback_waiver_unavailable', 'invalid_amount');
        }

        return new CommissionClawbackWaiverDecisionDTO(
            allowed: true,
            mode: 'unposted_full',
            maximumAmount: $amount,
            remainingWaivable: $amount,
            safeDenialKey: '',
            status: $status?->value ?? (string) $clawback->status,
            requiresAmountInput: false,
        );
    }

    private function decidePosted(
        CommissionClawback $clawback,
        ?WalletTransaction $matchingReversal,
    ): CommissionClawbackWaiverDecisionDTO {
        $reversal = null;
        if ($clawback->reversal_wallet_transaction_id !== null) {
            $reversal = WalletTransaction::query()->find($clawback->reversal_wallet_transaction_id);
        }
        $reversal ??= $matchingReversal;

        if ($reversal === null
            || $reversal->type !== WalletTransactionType::CommissionReversal
            || $reversal->status !== WalletTransaction::STATUS_POSTED
        ) {
            return $this->denied($clawback, 'messages.clawback_waiver_missing_reversal', 'missing_reversal');
        }

        $walletUserId = Wallet::query()->whereKey($reversal->wallet_id)->value('user_id');
        if ($walletUserId === null || (int) $walletUserId !== (int) $clawback->salesperson_id) {
            return $this->denied($clawback, 'messages.clawback_waiver_wrong_wallet', 'wrong_wallet');
        }

        if (! LedgerMoney::equals((string) $reversal->amount, (string) $clawback->amount)) {
            return $this->denied($clawback, 'messages.clawback_waiver_amount_mismatch', 'amount_mismatch');
        }

        $remaining = $this->arithmetic->remainingWaivable($clawback);
        if (LedgerMoney::compare($remaining, LedgerMoney::ZERO) !== 1) {
            return $this->denied($clawback, 'messages.clawback_waiver_nothing_remaining', 'fully_waived');
        }

        $isFull = LedgerMoney::equals($remaining, $this->arithmetic->postedReversalAmount($clawback));

        return new CommissionClawbackWaiverDecisionDTO(
            allowed: true,
            mode: $isFull ? 'posted_full' : 'posted_partial',
            maximumAmount: $remaining,
            remainingWaivable: $remaining,
            safeDenialKey: '',
            status: CommissionClawbackStatus::Posted->value,
            requiresAmountInput: true,
        );
    }

    private function findMatchingPostedReversal(CommissionClawback $clawback): ?WalletTransaction
    {
        return WalletTransaction::query()
            ->where('type', WalletTransactionType::CommissionReversal)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->where('idempotency_key', CommissionClawbackPolicy::reversalIdempotencyKey(
                (int) $clawback->commission_id,
                (int) $clawback->refund_wallet_transaction_id,
            ))
            ->first();
    }

    private function denied(
        CommissionClawback $clawback,
        string $key,
        string $mode,
    ): CommissionClawbackWaiverDecisionDTO {
        return new CommissionClawbackWaiverDecisionDTO(
            allowed: false,
            mode: $mode,
            maximumAmount: LedgerMoney::ZERO,
            remainingWaivable: $this->arithmetic->remainingWaivable($clawback),
            safeDenialKey: $key,
            status: $clawback->status instanceof CommissionClawbackStatus
                ? $clawback->status->value
                : (string) $clawback->status,
            requiresAmountInput: false,
        );
    }
}
