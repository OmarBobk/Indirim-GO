<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\DTOs\Admin\AdminCommissionClawbackDetailDTO;
use App\Enums\CommissionClawbackCorrectionReason;
use App\Enums\CommissionClawbackDecisionType;
use App\Enums\CommissionClawbackDisputeReason;
use App\Enums\CommissionClawbackDisputeResolution;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionClawbackWaiverReason;
use App\Enums\CommissionStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\CommissionClawback;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\CommissionClawbackPublicRef;
use App\Support\Commissions\CommissionClawbackActionRequiredQuery;
use App\Support\Commissions\CommissionClawbackCorrectionEligibility;
use App\Support\Commissions\CommissionClawbackDisputeEligibility;
use App\Support\Commissions\CommissionClawbackDisputeState;
use App\Support\Commissions\CommissionClawbackFailurePresentation;
use App\Support\Commissions\CommissionClawbackRetryEligibility;
use App\Support\Commissions\CommissionClawbackWaiverArithmetic;
use App\Support\Commissions\CommissionClawbackWaiverEligibility;
use App\Support\Commissions\SalespersonClawbackDebt;
use App\Support\LedgerMoney;

/**
 * Admin clawback detail read model (M7.2.1).
 */
final class GetAdminCommissionClawbackDetail
{
    public function __construct(
        private readonly CommissionClawbackRetryEligibility $eligibility = new CommissionClawbackRetryEligibility,
        private readonly CommissionClawbackWaiverEligibility $waiverEligibility = new CommissionClawbackWaiverEligibility,
        private readonly CommissionClawbackDisputeEligibility $disputeEligibility = new CommissionClawbackDisputeEligibility,
        private readonly CommissionClawbackCorrectionEligibility $correctionEligibility = new CommissionClawbackCorrectionEligibility,
        private readonly CommissionClawbackDisputeState $disputeState = new CommissionClawbackDisputeState,
        private readonly CommissionClawbackWaiverArithmetic $waiverArithmetic = new CommissionClawbackWaiverArithmetic,
        private readonly SalespersonClawbackDebt $debt = new SalespersonClawbackDebt,
    ) {}

    public function handle(User $actor, string $publicRef): AdminCommissionClawbackDetailDTO
    {
        abort_unless($actor->can('view_commission_clawbacks'), 404);

        $ref = CommissionClawbackPublicRef::normalize($publicRef);
        abort_unless(CommissionClawbackPublicRef::isValidFormat($ref), 404);

        $clawback = CommissionClawback::query()
            ->where('public_ref', $ref)
            ->with([
                'salesperson:id,name,email',
                'commission:id,order_id,fulfillment_id,commission_amount,status,wallet_transaction_id,salesperson_id',
                'commission.order:id,order_number',
                'fulfillment:id,status',
                'refundWalletTransaction:id,public_ref,status,type,amount',
                'originalCommissionCreditTransaction:id,public_ref,status,type,direction,amount,wallet_id',
                'reversalWalletTransaction:id,public_ref,status,type,amount',
            ])
            ->firstOrFail();

        $decision = $this->eligibility->decide($clawback);
        $waiverDecision = $this->waiverEligibility->decide($clawback);
        $disputeOpenDecision = $this->disputeEligibility->decideOpen($clawback);
        $disputeResolveDecision = $this->disputeEligibility->decideResolve($clawback);
        $correctionDecision = $this->correctionEligibility->decide($clawback);
        $failure = CommissionClawbackFailurePresentation::present($clawback->failure_code);
        $canProcess = $actor->can('process_commission_clawbacks');
        $canWaivePermission = $actor->can('waive_commission_clawbacks');
        $canManageDisputes = $actor->can('manage_commission_clawback_disputes');
        $canCorrectPermission = $actor->can('correct_commission_clawbacks');
        $hasActiveDispute = $this->disputeState->hasActiveDispute($clawback);
        $activeDispute = $this->disputeState->activeOpenDispute($clawback);

        $wallet = Wallet::forUser($clawback->salesperson ?? User::query()->findOrFail($clawback->salesperson_id));
        $hasDebt = $this->debt->hasOutstandingDebt($wallet);

        $commission = $clawback->commission;
        $credit = $clawback->originalCommissionCreditTransaction;
        $refund = $clawback->refundWalletTransaction;
        $reversal = $clawback->reversalWalletTransaction;

        $integrity = $this->integrityChecks($clawback, $commission, $credit, $refund);
        $remaining = $this->waiverArithmetic->remainingWaivable($clawback);
        $remainingCorrectable = $this->waiverArithmetic->remainingCorrectable($clawback);
        $correctionCredits = $this->waiverArithmetic->postedCorrectionCredits($clawback);
        $waivedCredits = $this->waiverArithmetic->postedWaiverCredits($clawback);
        $isPartiallyWaived = $clawback->status === CommissionClawbackStatus::Posted
            && LedgerMoney::compare($waivedCredits, LedgerMoney::ZERO) === 1;
        $isPartiallyCorrected = LedgerMoney::compare($correctionCredits, LedgerMoney::ZERO) === 1
            && LedgerMoney::compare($remainingCorrectable, LedgerMoney::ZERO) === 1;
        $isFullyCorrected = LedgerMoney::compare($correctionCredits, LedgerMoney::ZERO) === 1
            && LedgerMoney::compare($remainingCorrectable, LedgerMoney::ZERO) !== 1;

        $waiverRows = [];
        foreach ($this->waiverArithmetic->waiverDecisions($clawback) as $row) {
            $waiverRows[] = [
                'public_ref' => (string) $row->public_ref,
                'amount' => $row->amount !== null ? LedgerMoney::normalize((string) $row->amount) : null,
                'reason_code' => $row->reason_code instanceof CommissionClawbackWaiverReason
                    ? $row->reason_code->value
                    : (string) $row->reason_code,
                'status' => $row->status instanceof \App\Enums\CommissionClawbackDecisionStatus
                    ? $row->status->value
                    : (string) $row->status,
                'decided_at' => $row->decided_at?->toIso8601String() ?? '',
                'related_wtx' => $row->relatedWalletTransaction?->public_ref,
            ];
        }

        $reasonOptions = array_map(
            fn (CommissionClawbackWaiverReason $reason): array => [
                'value' => $reason->value,
                'label' => __($reason->labelKey()),
            ],
            CommissionClawbackWaiverReason::cases(),
        );

        $disputeReasonOptions = array_map(
            fn (CommissionClawbackDisputeReason $reason): array => [
                'value' => $reason->value,
                'label' => __($reason->labelKey()),
            ],
            CommissionClawbackDisputeReason::cases(),
        );

        $correctionReasonOptions = array_map(
            fn (CommissionClawbackCorrectionReason $reason): array => [
                'value' => $reason->value,
                'label' => __($reason->labelKey()),
            ],
            CommissionClawbackCorrectionReason::cases(),
        );

        $resolutionOptions = [];
        foreach (CommissionClawbackDisputeResolution::cases() as $resolution) {
            if ($resolution === CommissionClawbackDisputeResolution::AcceptedAsWaiver && ! $actor->can('waive_commission_clawbacks')) {
                continue;
            }
            if ($resolution === CommissionClawbackDisputeResolution::AcceptedAsCorrection && ! $actor->can('correct_commission_clawbacks')) {
                continue;
            }
            $resolutionOptions[] = [
                'value' => $resolution->value,
                'label' => __($resolution->labelKey()),
            ];
        }

        $correctionRows = [];
        foreach ($this->waiverArithmetic->correctionDecisions($clawback) as $row) {
            $correctionRows[] = [
                'public_ref' => (string) $row->public_ref,
                'amount' => $row->amount !== null ? LedgerMoney::normalize((string) $row->amount) : null,
                'reason_code' => (string) $row->reason_code,
                'status' => $row->status instanceof \App\Enums\CommissionClawbackDecisionStatus
                    ? $row->status->value
                    : (string) $row->status,
                'decided_at' => $row->decided_at?->toIso8601String() ?? '',
                'related_wtx' => $row->relatedWalletTransaction?->public_ref,
                'safe_summary' => is_string($row->safe_resolution_summary) ? $row->safe_resolution_summary : null,
            ];
        }

        $disputeRows = [];
        foreach ($this->disputeState->disputeTimeline($clawback) as $row) {
            $disputeRows[] = [
                'public_ref' => (string) $row->public_ref,
                'type' => $row->type instanceof CommissionClawbackDecisionType
                    ? $row->type->value
                    : (string) $row->type,
                'reason_code' => (string) $row->reason_code,
                'status' => $row->status instanceof \App\Enums\CommissionClawbackDecisionStatus
                    ? $row->status->value
                    : (string) $row->status,
                'decided_at' => $row->decided_at?->toIso8601String() ?? '',
                'safe_summary' => is_string($row->safe_resolution_summary) ? $row->safe_resolution_summary : null,
            ];
        }

        $activeDisputeReason = null;
        if ($activeDispute !== null) {
            $reason = CommissionClawbackDisputeReason::tryFrom((string) $activeDispute->reason_code);
            $activeDisputeReason = $reason !== null ? __($reason->labelKey()) : (string) $activeDispute->reason_code;
        }

        return new AdminCommissionClawbackDetailDTO(
            publicRef: (string) $clawback->public_ref,
            status: $clawback->status instanceof CommissionClawbackStatus
                ? $clawback->status->value
                : (string) $clawback->status,
            amount: LedgerMoney::normalize((string) $clawback->amount),
            currency: strtoupper((string) ($clawback->currency ?: 'USD')),
            policyVersion: (int) $clawback->policy_version,
            isRetryable: $decision->allowed,
            isStale: $decision->isStale,
            isActionRequired: CommissionClawbackActionRequiredQuery::isActionRequired(
                $clawback->status,
                $decision->isStale,
                is_string($clawback->failure_code) ? $clawback->failure_code : null,
                $hasActiveDispute,
            ),
            canRetry: $canProcess && $decision->allowed,
            retryDeniedKey: $decision->allowed
                ? ''
                : $decision->safeExplanationKey,
            failureTitle: $failure['title'],
            failureExplanation: $failure['explanation'],
            failureCategory: $failure['category'],
            failureCode: $clawback->failure_code,
            salespersonId: (int) $clawback->salesperson_id,
            salespersonName: $clawback->salesperson?->name,
            salespersonEmail: $clawback->salesperson?->email,
            walletBalance: LedgerMoney::normalize((string) $wallet->balance),
            outstandingDebt: $this->debt->amount($wallet),
            hasOutstandingDebt: $hasDebt,
            commissionAmount: LedgerMoney::normalize((string) ($commission?->commission_amount ?? '0')),
            commissionStatus: $commission?->status instanceof CommissionStatus
                ? $commission->status->value
                : (string) ($commission?->status ?? ''),
            orderNumber: $commission?->order?->order_number,
            orderId: $commission?->order_id !== null ? (int) $commission->order_id : null,
            fulfillmentStatus: $clawback->fulfillment?->status?->value
                ?? (is_string($clawback->fulfillment?->status) ? $clawback->fulfillment->status : null),
            refundPublicRef: $refund?->public_ref,
            refundStatus: $refund?->status,
            originalCreditPublicRef: $credit?->public_ref,
            reversalPublicRef: $reversal?->public_ref,
            createdAtIso: $clawback->created_at?->toIso8601String() ?? '',
            attemptedAtIso: $clawback->attempted_at?->toIso8601String(),
            postedAtIso: $clawback->posted_at?->toIso8601String(),
            needsReviewAtIso: $clawback->needs_review_at?->toIso8601String(),
            lastRetryAtIso: $clawback->last_retry_at?->toIso8601String(),
            retryCount: (int) $clawback->retry_count,
            integrityChecks: $integrity,
            timeline: $this->timeline($clawback),
            canWaive: $canWaivePermission && $waiverDecision->allowed,
            waiverDeniedKey: $waiverDecision->allowed ? '' : $waiverDecision->safeDenialKey,
            waiverMode: $waiverDecision->mode,
            remainingWaivable: $remaining,
            maximumWaivable: $waiverDecision->maximumAmount,
            requiresWaiverAmount: $waiverDecision->requiresAmountInput,
            isPartiallyWaived: $isPartiallyWaived,
            netCollected: $this->waiverArithmetic->netCollected($clawback),
            waiverDecisions: $waiverRows,
            waiverReasonOptions: $reasonOptions,
            canOpenDispute: $canManageDisputes && $disputeOpenDecision->allowed,
            canResolveDispute: $canManageDisputes && $disputeResolveDecision->allowed,
            canCorrect: $canCorrectPermission && $correctionDecision->allowed,
            disputeDeniedKey: $hasActiveDispute
                ? ($disputeResolveDecision->allowed ? '' : $disputeResolveDecision->safeDenialKey)
                : ($disputeOpenDecision->allowed ? '' : $disputeOpenDecision->safeDenialKey),
            correctionDeniedKey: $correctionDecision->allowed ? '' : $correctionDecision->safeDenialKey,
            remainingCorrectable: $remainingCorrectable,
            maximumCorrectable: $correctionDecision->maximumAmount,
            requiresCorrectionAmount: $correctionDecision->requiresAmountInput,
            activeDisputeRef: $activeDispute?->public_ref,
            activeDisputeReason: $activeDisputeReason,
            isDisputed: $hasActiveDispute,
            isPartiallyCorrected: $isPartiallyCorrected,
            isFullyCorrected: $isFullyCorrected,
            correctionDecisions: $correctionRows,
            disputeDecisions: $disputeRows,
            disputeReasonOptions: $disputeReasonOptions,
            correctionReasonOptions: $correctionReasonOptions,
            resolutionOptions: $resolutionOptions,
        );
    }

    /**
     * @return list<array{key: string, ok: bool, label_key: string}>
     */
    private function integrityChecks(
        CommissionClawback $clawback,
        mixed $commission,
        ?WalletTransaction $credit,
        ?WalletTransaction $refund,
    ): array {
        $commissionOk = $commission !== null;
        $creditedOk = $commissionOk && $commission->status === CommissionStatus::Credited;
        $fulfillmentOk = $clawback->fulfillment_id === null
            || ($commissionOk && (int) $commission->fulfillment_id === (int) $clawback->fulfillment_id);
        $refundOk = $refund !== null
            && $refund->type === WalletTransactionType::Refund
            && $refund->status === WalletTransaction::STATUS_POSTED;
        $creditExists = $credit !== null;
        $creditPosted = $creditExists && $credit->status === WalletTransaction::STATUS_POSTED;
        $creditTypeOk = $creditExists
            && $credit->type === WalletTransactionType::CommissionCredit
            && $credit->direction === WalletTransactionDirection::Credit;
        $walletOk = false;
        if ($creditExists && $commissionOk) {
            $walletUserId = Wallet::query()->whereKey($credit->wallet_id)->value('user_id');
            $walletOk = $walletUserId !== null && (int) $walletUserId === (int) $clawback->salesperson_id;
        }
        $amountOk = $creditExists && $commissionOk
            && LedgerMoney::equals((string) $credit->amount, (string) $commission->commission_amount)
            && LedgerMoney::equals((string) $clawback->amount, (string) $commission->commission_amount);
        $reversalUnique = $clawback->reversal_wallet_transaction_id === null
            || WalletTransaction::query()
                ->whereKey($clawback->reversal_wallet_transaction_id)
                ->where('type', WalletTransactionType::CommissionReversal)
                ->exists();

        return [
            ['key' => 'commission_exists', 'ok' => $commissionOk, 'label_key' => 'messages.clawback_check_commission_exists'],
            ['key' => 'commission_credited', 'ok' => $creditedOk, 'label_key' => 'messages.clawback_check_commission_credited'],
            ['key' => 'fulfillment_match', 'ok' => $fulfillmentOk, 'label_key' => 'messages.clawback_check_fulfillment'],
            ['key' => 'refund_match', 'ok' => $refundOk, 'label_key' => 'messages.clawback_check_refund'],
            ['key' => 'credit_exists', 'ok' => $creditExists, 'label_key' => 'messages.clawback_check_credit_exists'],
            ['key' => 'credit_posted', 'ok' => $creditPosted, 'label_key' => 'messages.clawback_check_credit_posted'],
            ['key' => 'credit_type', 'ok' => $creditTypeOk, 'label_key' => 'messages.clawback_check_credit_type'],
            ['key' => 'wallet_owner', 'ok' => $walletOk, 'label_key' => 'messages.clawback_check_wallet'],
            ['key' => 'amount_match', 'ok' => $amountOk, 'label_key' => 'messages.clawback_check_amount'],
            ['key' => 'reversal_unique', 'ok' => $reversalUnique, 'label_key' => 'messages.clawback_check_reversal'],
            ['key' => 'policy_version', 'ok' => (int) $clawback->policy_version > 0, 'label_key' => 'messages.clawback_check_policy'],
        ];
    }

    /**
     * @return list<array{at: string, label_key: string, detail: ?string}>
     */
    private function timeline(CommissionClawback $clawback): array
    {
        $rows = [];

        if ($clawback->created_at !== null) {
            $rows[] = [
                'at' => $clawback->created_at->toIso8601String(),
                'label_key' => 'messages.clawback_timeline_created',
                'detail' => null,
            ];
        }

        if ($clawback->attempted_at !== null) {
            $rows[] = [
                'at' => $clawback->attempted_at->toIso8601String(),
                'label_key' => 'messages.clawback_timeline_attempted',
                'detail' => null,
            ];
        }

        if ($clawback->last_retry_at !== null) {
            $rows[] = [
                'at' => $clawback->last_retry_at->toIso8601String(),
                'label_key' => 'messages.clawback_timeline_retry',
                'detail' => (string) $clawback->retry_count,
            ];
        }

        if ($clawback->needs_review_at !== null) {
            $rows[] = [
                'at' => $clawback->needs_review_at->toIso8601String(),
                'label_key' => 'messages.clawback_timeline_needs_review',
                'detail' => null,
            ];
        }

        if ($clawback->posted_at !== null) {
            $rows[] = [
                'at' => $clawback->posted_at->toIso8601String(),
                'label_key' => 'messages.clawback_timeline_posted',
                'detail' => $clawback->reversalWalletTransaction?->public_ref,
            ];
        }

        usort($rows, fn (array $a, array $b): int => strcmp($a['at'], $b['at']));

        return $rows;
    }
}
