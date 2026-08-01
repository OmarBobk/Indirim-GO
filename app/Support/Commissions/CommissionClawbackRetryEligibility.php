<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\DTOs\Admin\CommissionClawbackRetryDecisionDTO;
use App\Enums\CommissionClawbackStatus;
use App\Models\CommissionClawback;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Server-only retry eligibility for commission clawbacks (M7.2.1).
 */
final class CommissionClawbackRetryEligibility
{
    public function decide(CommissionClawback $clawback, ?CarbonInterface $now = null): CommissionClawbackRetryDecisionDTO
    {
        $now ??= now();

        if ($clawback->status === CommissionClawbackStatus::Posted) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: false,
                reasonCode: 'already_posted',
                safeExplanationKey: 'messages.clawback_retry_already_posted',
                nextActionKey: 'messages.clawback_next_none',
            );
        }

        if ($clawback->status === CommissionClawbackStatus::Waived) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: false,
                reasonCode: 'waived',
                safeExplanationKey: 'messages.clawback_retry_waived',
                nextActionKey: 'messages.clawback_next_none',
            );
        }

        if ((new CommissionClawbackDisputeState)->hasActiveDispute($clawback)) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: false,
                reasonCode: 'disputed',
                safeExplanationKey: 'messages.clawback_retry_disputed',
                nextActionKey: 'messages.clawback_next_review',
            );
        }

        if ($clawback->reversal_wallet_transaction_id !== null) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: false,
                reasonCode: 'reversal_already_linked',
                safeExplanationKey: 'messages.clawback_retry_already_posted',
                nextActionKey: 'messages.clawback_next_review',
            );
        }

        $isStale = $this->isStaleProcessing($clawback, $now);

        if ($isStale) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: true,
                reasonCode: 'stale_processing',
                safeExplanationKey: 'messages.clawback_retry_stale_allowed',
                nextActionKey: 'messages.clawback_next_retry',
                isStale: true,
                targetStatus: CommissionClawbackStatus::Pending->value,
            );
        }

        if ($clawback->status === CommissionClawbackStatus::Processing) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: false,
                reasonCode: 'still_processing',
                safeExplanationKey: 'messages.clawback_retry_still_processing',
                nextActionKey: 'messages.clawback_next_wait',
            );
        }

        if ($clawback->status === CommissionClawbackStatus::Pending) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: true,
                reasonCode: 'pending_redispatch',
                safeExplanationKey: 'messages.clawback_retry_pending_allowed',
                nextActionKey: 'messages.clawback_next_retry',
                targetStatus: CommissionClawbackStatus::Pending->value,
            );
        }

        $failureCode = is_string($clawback->failure_code) ? $clawback->failure_code : null;

        if (CommissionClawbackFailurePresentation::isRetryableCode($failureCode)) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: true,
                reasonCode: (string) $failureCode,
                safeExplanationKey: 'messages.clawback_retry_operational_allowed',
                nextActionKey: 'messages.clawback_next_retry',
                targetStatus: CommissionClawbackStatus::Pending->value,
            );
        }

        if (
            in_array($clawback->status, [
                CommissionClawbackStatus::NeedsReview,
                CommissionClawbackStatus::Failed,
            ], true)
            && CommissionClawbackFailurePresentation::isIntegrityCode($failureCode)
        ) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: false,
                reasonCode: (string) ($failureCode ?: 'integrity_anomaly'),
                safeExplanationKey: 'messages.clawback_retry_integrity_denied',
                nextActionKey: 'messages.clawback_next_fix_source',
            );
        }

        if (
            in_array($clawback->status, [
                CommissionClawbackStatus::NeedsReview,
                CommissionClawbackStatus::Failed,
            ], true)
        ) {
            return new CommissionClawbackRetryDecisionDTO(
                allowed: false,
                reasonCode: $failureCode ?: 'unknown_failure',
                safeExplanationKey: 'messages.clawback_retry_unknown_denied',
                nextActionKey: 'messages.clawback_next_review',
            );
        }

        return new CommissionClawbackRetryDecisionDTO(
            allowed: false,
            reasonCode: 'not_retryable',
            safeExplanationKey: 'messages.clawback_retry_unavailable',
            nextActionKey: 'messages.clawback_next_none',
        );
    }

    public function isStaleProcessing(CommissionClawback $clawback, ?CarbonInterface $now = null): bool
    {
        if ($clawback->status !== CommissionClawbackStatus::Processing) {
            return false;
        }

        if ($clawback->reversal_wallet_transaction_id !== null) {
            return false;
        }

        $attemptedAt = $clawback->attempted_at;
        if ($attemptedAt === null) {
            return true;
        }

        $now ??= now();
        $minutes = max(1, (int) config('billing.commission_clawback.processing_stale_minutes', 30));

        return $attemptedAt->lte(Carbon::instance($now)->subMinutes($minutes));
    }
}
