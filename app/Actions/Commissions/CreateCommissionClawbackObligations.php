<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Commission;
use App\Models\CommissionClawback;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\WalletTransaction;
use App\Support\CommissionClawbackPublicRef;
use App\Support\Commissions\CommissionClawbackPolicy;
use App\Support\LedgerMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Creates durable clawback obligations for credited commissions related to a posted refund.
 * Does not debit wallets — ProcessCommissionClawback posts after commit.
 */
final class CreateCommissionClawbackObligations
{
    /**
     * @return list<int> Newly created or existing processable clawback IDs
     */
    public function handle(
        WalletTransaction $refundTransaction,
        ?Fulfillment $fulfillment,
        Order $order,
    ): array {
        if (! CommissionClawbackPolicy::isEffective()) {
            return [];
        }

        if ($refundTransaction->status !== WalletTransaction::STATUS_POSTED) {
            return [];
        }

        if ($refundTransaction->type !== WalletTransactionType::Refund) {
            return [];
        }

        if ($fulfillment === null) {
            return [];
        }

        $commissions = Commission::query()
            ->where('fulfillment_id', $fulfillment->id)
            ->where('status', CommissionStatus::Credited)
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $ids = [];

        foreach ($commissions as $commission) {
            $clawback = $this->createOrFind($commission, $refundTransaction);

            if ($clawback !== null) {
                $ids[] = (int) $clawback->id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function createOrFind(Commission $commission, WalletTransaction $refundTransaction): ?CommissionClawback
    {
        $idempotencyKey = CommissionClawbackPolicy::idempotencyKey(
            (int) $commission->id,
            (int) $refundTransaction->id,
        );

        $existing = CommissionClawback::query()
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $existing = CommissionClawback::query()
            ->where('commission_id', $commission->id)
            ->where('refund_wallet_transaction_id', $refundTransaction->id)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $amount = LedgerMoney::normalizePositive((string) $commission->commission_amount);
        } catch (\InvalidArgumentException $exception) {
            Log::warning('commission.clawback.invalid_amount', [
                'commission_id' => $commission->id,
                'refund_tx_id' => $refundTransaction->id,
            ]);

            return $this->createNeedsReviewStub($commission, $refundTransaction, $idempotencyKey, 'invalid_amount');
        }

        $originalCreditId = $commission->wallet_transaction_id !== null
            ? (int) $commission->wallet_transaction_id
            : null;

        $status = CommissionClawbackStatus::Pending;
        $failureCode = null;
        $failureMessage = null;
        $needsReviewAt = null;

        $credit = $originalCreditId !== null
            ? WalletTransaction::query()->find($originalCreditId)
            : null;

        if ($credit === null
            || $credit->type !== WalletTransactionType::CommissionCredit
            || $credit->status !== WalletTransaction::STATUS_POSTED
            || ! LedgerMoney::equals($amount, (string) $credit->amount)
        ) {
            $status = CommissionClawbackStatus::NeedsReview;
            $failureCode = 'invalid_original_credit';
            $failureMessage = 'Original commission credit is missing or mismatched.';
            $needsReviewAt = now();
        }

        try {
            return CommissionClawbackPublicRef::withUniqueRetry(function (string $publicRef) use (
                $commission,
                $refundTransaction,
                $amount,
                $idempotencyKey,
                $originalCreditId,
                $status,
                $failureCode,
                $failureMessage,
                $needsReviewAt,
            ): CommissionClawback {
                return CommissionClawback::query()->create([
                    'public_ref' => $publicRef,
                    'commission_id' => $commission->id,
                    'salesperson_id' => $commission->salesperson_id,
                    'fulfillment_id' => $commission->fulfillment_id,
                    'refund_wallet_transaction_id' => $refundTransaction->id,
                    'original_commission_credit_transaction_id' => $originalCreditId,
                    'reversal_wallet_transaction_id' => null,
                    'amount' => $amount,
                    'currency' => 'USD',
                    'status' => $status,
                    'policy_version' => CommissionClawbackPolicy::policyVersion(),
                    'idempotency_key' => $idempotencyKey,
                    'failure_code' => $failureCode,
                    'failure_message_safe' => $failureMessage,
                    'needs_review_at' => $needsReviewAt,
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueObligationConstraint($exception)) {
                return CommissionClawback::query()
                    ->where('idempotency_key', $idempotencyKey)
                    ->orWhere(function ($query) use ($commission, $refundTransaction): void {
                        $query->where('commission_id', $commission->id)
                            ->where('refund_wallet_transaction_id', $refundTransaction->id);
                    })
                    ->first();
            }

            throw $exception;
        }
    }

    private function createNeedsReviewStub(
        Commission $commission,
        WalletTransaction $refundTransaction,
        string $idempotencyKey,
        string $failureCode,
    ): ?CommissionClawback {
        try {
            return CommissionClawbackPublicRef::withUniqueRetry(function (string $publicRef) use (
                $commission,
                $refundTransaction,
                $idempotencyKey,
                $failureCode,
            ): CommissionClawback {
                return CommissionClawback::query()->create([
                    'public_ref' => $publicRef,
                    'commission_id' => $commission->id,
                    'salesperson_id' => $commission->salesperson_id,
                    'fulfillment_id' => $commission->fulfillment_id,
                    'refund_wallet_transaction_id' => $refundTransaction->id,
                    'original_commission_credit_transaction_id' => $commission->wallet_transaction_id,
                    'amount' => LedgerMoney::ZERO,
                    'currency' => 'USD',
                    'status' => CommissionClawbackStatus::NeedsReview,
                    'policy_version' => CommissionClawbackPolicy::policyVersion(),
                    'idempotency_key' => $idempotencyKey,
                    'failure_code' => $failureCode,
                    'failure_message_safe' => 'Commission amount could not be normalised.',
                    'needs_review_at' => now(),
                ]);
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueObligationConstraint($exception)) {
                return CommissionClawback::query()->where('idempotency_key', $idempotencyKey)->first();
            }

            Log::warning('commission.clawback.create_failed', [
                'commission_id' => $commission->id,
                'message' => $exception->getMessage(),
            ]);

            return null;
        }
    }

    private function isUniqueObligationConstraint(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'unique')
            || str_contains($message, 'duplicate')
            || (string) $exception->getCode() === '23000';
    }
}
