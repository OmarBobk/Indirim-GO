<?php

declare(strict_types=1);

namespace App\Support\Refunds;

use App\Enums\CustomerRefundStatus;
use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\WalletTransaction;

/**
 * Classifies refund WalletTransaction rows for customer workspace presentation.
 * Query-free when fulfillment status is supplied; never invents timestamps.
 */
final class CustomerRefundClassifier
{
    public static function classify(
        WalletTransaction $transaction,
        ?FulfillmentStatus $fulfillmentStatus = null,
    ): CustomerRefundStatus {
        if ($transaction->status === WalletTransaction::STATUS_PENDING) {
            return CustomerRefundStatus::UnderReview;
        }

        if ($transaction->status === WalletTransaction::STATUS_POSTED) {
            return CustomerRefundStatus::Refunded;
        }

        if ($transaction->status !== WalletTransaction::STATUS_REJECTED) {
            return CustomerRefundStatus::IntegrityAnomaly;
        }

        $dismissReason = data_get($transaction->meta, 'dismiss_reason');
        if (is_string($dismissReason) && trim($dismissReason) !== '') {
            return CustomerRefundStatus::Closed;
        }

        if ($fulfillmentStatus === FulfillmentStatus::Failed) {
            return CustomerRefundStatus::NeedsAction;
        }

        return CustomerRefundStatus::Closed;
    }

    public static function moneyMoved(CustomerRefundStatus $status): bool
    {
        return $status === CustomerRefundStatus::Refunded;
    }

    public static function canCustomerRecover(CustomerRefundStatus $status): bool
    {
        return $status === CustomerRefundStatus::NeedsAction;
    }

    /**
     * Customer-safe rejection/dismissal copy. Escaped later in Blade.
     */
    public static function customerSafeReason(WalletTransaction $transaction): ?string
    {
        $note = data_get($transaction->meta, 'note');
        if (! is_string($note)) {
            return null;
        }

        $trimmed = trim($note);
        if ($trimmed === '') {
            return null;
        }

        // Strip system-appended dismiss notes that are operational, not customer copy.
        if (str_contains(strtolower($trimmed), 'dismissed:')) {
            return null;
        }

        if (mb_strlen($trimmed) > 280) {
            $trimmed = mb_substr($trimmed, 0, 277).'…';
        }

        return $trimmed;
    }
}
