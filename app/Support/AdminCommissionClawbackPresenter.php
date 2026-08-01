<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\Admin\AdminCommissionClawbackDetailDTO;
use App\DTOs\Admin\AdminCommissionClawbackListItemDTO;
use Illuminate\Support\Carbon;

/**
 * Query-free admin clawback presenter (M7.2.1).
 */
final class AdminCommissionClawbackPresenter
{
    /**
     * @param  list<AdminCommissionClawbackListItemDTO>  $items
     * @return list<array<string, mixed>>
     */
    public function presentList(array $items): array
    {
        return array_map(fn (AdminCommissionClawbackListItemDTO $item): array => $this->presentListItem($item), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentListItem(AdminCommissionClawbackListItemDTO $item): array
    {
        return [
            'public_ref' => $item->publicRef,
            'status' => $item->status,
            'status_label' => $this->statusLabel($item->status),
            'amount' => $item->amount,
            'currency' => $item->currency,
            'salesperson_name' => $item->salespersonName,
            'salesperson_email' => $item->salespersonEmail,
            'order_number' => $item->orderNumber,
            'refund_public_ref' => $item->refundPublicRef,
            'original_credit_public_ref' => $item->originalCreditPublicRef,
            'reversal_public_ref' => $item->reversalPublicRef,
            'failure_category' => $item->failureCategory,
            'is_retryable' => $item->isRetryable,
            'is_stale' => $item->isStale,
            'is_action_required' => $item->isActionRequired,
            'has_outstanding_debt' => $item->hasOutstandingDebt,
            'debt_recovered' => $item->debtRecovered,
            'is_partially_waived' => $item->isPartiallyWaived,
            'is_disputed' => $item->isDisputed,
            'is_partially_corrected' => $item->isPartiallyCorrected,
            'is_fully_corrected' => $item->isFullyCorrected,
            'is_correction_available' => $item->isCorrectionAvailable,
            'is_net_collected_zero' => $item->isNetCollectedZero,
            'policy_version' => $item->policyVersion,
            'created_at_display' => $this->formatTime($item->createdAtIso),
            'attempted_at_display' => $this->formatTime($item->attemptedAtIso),
            'posted_at_display' => $this->formatTime($item->postedAtIso),
            'href' => route('admin.commission-clawbacks.show', ['clawback' => $item->publicRef]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDetail(AdminCommissionClawbackDetailDTO $detail): array
    {
        return [
            'public_ref' => $detail->publicRef,
            'status' => $detail->status,
            'status_label' => $this->statusLabel($detail->status),
            'amount' => $detail->amount,
            'currency' => $detail->currency,
            'policy_version' => $detail->policyVersion,
            'is_retryable' => $detail->isRetryable,
            'is_stale' => $detail->isStale,
            'is_action_required' => $detail->isActionRequired,
            'can_retry' => $detail->canRetry,
            'retry_denied' => $detail->retryDeniedKey !== '' ? __($detail->retryDeniedKey) : null,
            'failure_title' => $detail->failureTitle,
            'failure_explanation' => $detail->failureExplanation,
            'failure_category' => $detail->failureCategory,
            'salesperson_name' => $detail->salespersonName,
            'salesperson_email' => $detail->salespersonEmail,
            'wallet_balance' => $detail->walletBalance,
            'outstanding_debt' => $detail->outstandingDebt,
            'has_outstanding_debt' => $detail->hasOutstandingDebt,
            'commission_amount' => $detail->commissionAmount,
            'commission_status' => $detail->commissionStatus,
            'order_number' => $detail->orderNumber,
            'order_href' => $detail->orderId !== null && $detail->orderNumber !== null
                ? route('admin.orders.show', ['order' => $detail->orderId])
                : null,
            'fulfillment_status' => $detail->fulfillmentStatus,
            'refund_public_ref' => $detail->refundPublicRef,
            'refund_status' => $detail->refundStatus,
            'original_credit_public_ref' => $detail->originalCreditPublicRef,
            'reversal_public_ref' => $detail->reversalPublicRef,
            'created_at_display' => $this->formatTime($detail->createdAtIso),
            'attempted_at_display' => $this->formatTime($detail->attemptedAtIso),
            'posted_at_display' => $this->formatTime($detail->postedAtIso),
            'needs_review_at_display' => $this->formatTime($detail->needsReviewAtIso),
            'last_retry_at_display' => $this->formatTime($detail->lastRetryAtIso),
            'retry_count' => $detail->retryCount,
            'integrity_checks' => array_map(fn (array $check): array => [
                'key' => $check['key'],
                'ok' => $check['ok'],
                'label' => __($check['label_key']),
            ], $detail->integrityChecks),
            'timeline' => array_map(fn (array $row): array => [
                'at_display' => $this->formatTime($row['at']),
                'label' => __($row['label_key']),
                'detail' => $row['detail'],
            ], $detail->timeline),
            'inbox_href' => route('admin.commission-clawbacks.index'),
            'can_waive' => $detail->canWaive,
            'waiver_denied' => $detail->waiverDeniedKey !== '' ? __($detail->waiverDeniedKey) : null,
            'waiver_mode' => $detail->waiverMode,
            'remaining_waivable' => $detail->remainingWaivable,
            'maximum_waivable' => $detail->maximumWaivable,
            'requires_waiver_amount' => $detail->requiresWaiverAmount,
            'is_partially_waived' => $detail->isPartiallyWaived,
            'net_collected' => $detail->netCollected,
            'waiver_reason_options' => $detail->waiverReasonOptions,
            'waiver_decisions' => array_map(fn (array $row): array => [
                'public_ref' => $row['public_ref'],
                'amount' => $row['amount'],
                'reason_label' => __('messages.clawback_waiver_reason_'.$row['reason_code']),
                'status' => $row['status'],
                'decided_at_display' => $this->formatTime($row['decided_at']),
                'related_wtx' => $row['related_wtx'],
            ], $detail->waiverDecisions),
            'can_open_dispute' => $detail->canOpenDispute,
            'can_resolve_dispute' => $detail->canResolveDispute,
            'can_correct' => $detail->canCorrect,
            'dispute_denied' => $detail->disputeDeniedKey !== '' ? __($detail->disputeDeniedKey) : null,
            'correction_denied' => $detail->correctionDeniedKey !== '' ? __($detail->correctionDeniedKey) : null,
            'remaining_correctable' => $detail->remainingCorrectable,
            'maximum_correctable' => $detail->maximumCorrectable,
            'requires_correction_amount' => $detail->requiresCorrectionAmount,
            'active_dispute_ref' => $detail->activeDisputeRef,
            'active_dispute_reason' => $detail->activeDisputeReason,
            'is_disputed' => $detail->isDisputed,
            'is_partially_corrected' => $detail->isPartiallyCorrected,
            'is_fully_corrected' => $detail->isFullyCorrected,
            'dispute_reason_options' => $detail->disputeReasonOptions,
            'correction_reason_options' => $detail->correctionReasonOptions,
            'resolution_options' => $detail->resolutionOptions,
            'dispute_decisions' => array_map(fn (array $row): array => [
                'public_ref' => $row['public_ref'],
                'type' => $row['type'],
                'type_label' => $row['type'] === 'dispute_opened'
                    ? __('messages.clawback_dispute_opened_label')
                    : __('messages.clawback_dispute_resolved_label'),
                'reason_label' => $row['type'] === 'dispute_resolved'
                    ? __('messages.clawback_dispute_resolution_'.$row['reason_code'])
                    : __('messages.clawback_dispute_reason_'.$row['reason_code']),
                'status' => $row['status'],
                'decided_at_display' => $this->formatTime($row['decided_at']),
                'safe_summary' => $row['safe_summary'],
            ], $detail->disputeDecisions),
            'correction_decisions' => array_map(fn (array $row): array => [
                'public_ref' => $row['public_ref'],
                'amount' => $row['amount'],
                'reason_label' => __('messages.clawback_correction_reason_'.$row['reason_code']),
                'status' => $row['status'],
                'decided_at_display' => $this->formatTime($row['decided_at']),
                'related_wtx' => $row['related_wtx'],
                'safe_summary' => $row['safe_summary'],
            ], $detail->correctionDecisions),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending' => __('messages.clawback_status_pending'),
            'processing' => __('messages.clawback_status_processing'),
            'posted' => __('messages.clawback_status_posted'),
            'needs_review' => __('messages.clawback_status_needs_review'),
            'failed' => __('messages.clawback_status_failed'),
            'waived' => __('messages.clawback_status_waived'),
            default => $status,
        };
    }

    private function formatTime(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return Carbon::parse($iso)->timezone(config('app.timezone'))->format('Y-m-d H:i');
    }
}
