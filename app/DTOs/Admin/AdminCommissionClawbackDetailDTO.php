<?php

declare(strict_types=1);

namespace App\DTOs\Admin;

/**
 * Admin clawback detail read model (M7.2.1 / M7.2.2).
 *
 * @param  list<array{key: string, ok: bool, label_key: string}>  $integrityChecks
 * @param  list<array{at: string, label_key: string, detail: ?string}>  $timeline
 * @param  list<array{public_ref: string, amount: ?string, reason_code: string, status: string, decided_at: string, related_wtx: ?string}>  $waiverDecisions
 * @param  list<array{public_ref: string, amount: ?string, reason_code: string, status: string, decided_at: string, related_wtx: ?string, safe_summary: ?string}>  $correctionDecisions
 * @param  list<array{public_ref: string, type: string, reason_code: string, status: string, decided_at: string, safe_summary: ?string}>  $disputeDecisions
 */
final readonly class AdminCommissionClawbackDetailDTO
{
    /**
     * @param  list<array{key: string, ok: bool, label_key: string}>  $integrityChecks
     * @param  list<array{at: string, label_key: string, detail: ?string}>  $timeline
     * @param  list<array{public_ref: string, amount: ?string, reason_code: string, status: string, decided_at: string, related_wtx: ?string}>  $waiverDecisions
     * @param  list<array{value: string, label: string}>  $waiverReasonOptions
     * @param  list<array{public_ref: string, amount: ?string, reason_code: string, status: string, decided_at: string, related_wtx: ?string, safe_summary: ?string}>  $correctionDecisions
     * @param  list<array{public_ref: string, type: string, reason_code: string, status: string, decided_at: string, safe_summary: ?string}>  $disputeDecisions
     * @param  list<array{value: string, label: string}>  $disputeReasonOptions
     * @param  list<array{value: string, label: string}>  $correctionReasonOptions
     * @param  list<array{value: string, label: string}>  $resolutionOptions
     */
    public function __construct(
        public string $publicRef,
        public string $status,
        public string $amount,
        public string $currency,
        public int $policyVersion,
        public bool $isRetryable,
        public bool $isStale,
        public bool $isActionRequired,
        public bool $canRetry,
        public string $retryDeniedKey,
        public string $failureTitle,
        public string $failureExplanation,
        public string $failureCategory,
        public ?string $failureCode,
        public int $salespersonId,
        public ?string $salespersonName,
        public ?string $salespersonEmail,
        public string $walletBalance,
        public string $outstandingDebt,
        public bool $hasOutstandingDebt,
        public string $commissionAmount,
        public string $commissionStatus,
        public ?string $orderNumber,
        public ?int $orderId,
        public ?string $fulfillmentStatus,
        public ?string $refundPublicRef,
        public ?string $refundStatus,
        public ?string $originalCreditPublicRef,
        public ?string $reversalPublicRef,
        public string $createdAtIso,
        public ?string $attemptedAtIso,
        public ?string $postedAtIso,
        public ?string $needsReviewAtIso,
        public ?string $lastRetryAtIso,
        public int $retryCount,
        public array $integrityChecks,
        public array $timeline,
        public bool $canWaive = false,
        public string $waiverDeniedKey = '',
        public string $waiverMode = '',
        public string $remainingWaivable = '0.00',
        public string $maximumWaivable = '0.00',
        public bool $requiresWaiverAmount = false,
        public bool $isPartiallyWaived = false,
        public string $netCollected = '0.00',
        public array $waiverDecisions = [],
        public array $waiverReasonOptions = [],
        public bool $canOpenDispute = false,
        public bool $canResolveDispute = false,
        public bool $canCorrect = false,
        public string $disputeDeniedKey = '',
        public string $correctionDeniedKey = '',
        public string $remainingCorrectable = '0.00',
        public string $maximumCorrectable = '0.00',
        public bool $requiresCorrectionAmount = true,
        public ?string $activeDisputeRef = null,
        public ?string $activeDisputeReason = null,
        public bool $isDisputed = false,
        public bool $isPartiallyCorrected = false,
        public bool $isFullyCorrected = false,
        public array $correctionDecisions = [],
        public array $disputeDecisions = [],
        public array $disputeReasonOptions = [],
        public array $correctionReasonOptions = [],
        public array $resolutionOptions = [],
    ) {}
}
