<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use App\Enums\CommissionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Commission;
use App\Models\CommissionClawback;
use App\Models\Fulfillment;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Classifies historical commission exposure candidates (M7.2.4).
 * Never creates obligations or wallet posts.
 */
final class HistoricalCommissionExposureClassifier
{
    public const CONFIDENCE_CONFIRMED = 'confirmed';

    public const CONFIDENCE_INCOMPLETE = 'incomplete';

    public function policyEffectiveAt(): ?Carbon
    {
        $raw = config('billing.commission_clawback.effective_at');
        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Refunds posted strictly before policy effective_at are outside automatic clawback.
     * When effective_at is unset, any credited+refund pair without a clawback is a pre-feature gap.
     */
    public function isOutsideAutomaticPolicy(?CarbonInterface $refundPostedAt): bool
    {
        $effectiveAt = $this->policyEffectiveAt();
        if ($effectiveAt === null) {
            return true;
        }

        if ($refundPostedAt === null) {
            return false;
        }

        return Carbon::instance($refundPostedAt)->lt($effectiveAt);
    }

    /**
     * @return array{
     *     eligible: bool,
     *     confidence: string,
     *     exposure_amount: string,
     *     denial: string
     * }
     */
    public function classify(
        Commission $commission,
        WalletTransaction $refund,
        ?WalletTransaction $credit,
        ?Fulfillment $fulfillment,
    ): array {
        if ($commission->status !== CommissionStatus::Credited) {
            return $this->ineligible('not_credited');
        }

        if ($refund->type !== WalletTransactionType::Refund
            || $refund->status !== WalletTransaction::STATUS_POSTED
        ) {
            return $this->ineligible('refund_not_posted');
        }

        if (! $this->isOutsideAutomaticPolicy($refund->posted_at)) {
            return $this->ineligible('inside_policy_window');
        }

        if ($this->hasClawbackObligation((int) $commission->id, (int) $refund->id)) {
            return $this->ineligible('has_clawback_obligation');
        }

        if ($this->hasPostedReversal((int) $commission->id, (int) $refund->id)) {
            return $this->ineligible('has_reversal');
        }

        $relationshipOk = $this->refundMatchesFulfillment($refund, $fulfillment, $commission);
        $creditOk = $credit !== null
            && $credit->type === WalletTransactionType::CommissionCredit
            && $credit->status === WalletTransaction::STATUS_POSTED
            && (int) $credit->id === (int) ($commission->wallet_transaction_id ?? 0);

        try {
            $amount = LedgerMoney::normalizePositive((string) $commission->commission_amount);
        } catch (\Throwable) {
            return [
                'eligible' => true,
                'confidence' => self::CONFIDENCE_INCOMPLETE,
                'exposure_amount' => LedgerMoney::ZERO,
                'denial' => '',
            ];
        }

        if ($fulfillment === null || ! $relationshipOk || ! $creditOk) {
            return [
                'eligible' => true,
                'confidence' => self::CONFIDENCE_INCOMPLETE,
                'exposure_amount' => $amount,
                'denial' => '',
            ];
        }

        return [
            'eligible' => true,
            'confidence' => self::CONFIDENCE_CONFIRMED,
            'exposure_amount' => $amount,
            'denial' => '',
        ];
    }

    public function hasClawbackObligation(int $commissionId, int $refundId): bool
    {
        return CommissionClawback::query()
            ->where('commission_id', $commissionId)
            ->where('refund_wallet_transaction_id', $refundId)
            ->exists();
    }

    public function hasPostedReversal(int $commissionId, int $refundId): bool
    {
        return WalletTransaction::query()
            ->where('type', WalletTransactionType::CommissionReversal)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->where('idempotency_key', CommissionClawbackPolicy::reversalIdempotencyKey($commissionId, $refundId))
            ->exists();
    }

    public function refundMatchesFulfillment(
        WalletTransaction $refund,
        ?Fulfillment $fulfillment,
        Commission $commission,
    ): bool {
        if ($fulfillment === null) {
            return false;
        }

        if ((int) $commission->fulfillment_id !== (int) $fulfillment->id) {
            return false;
        }

        if ($refund->reference_type === Fulfillment::class
            && (int) $refund->reference_id === (int) $fulfillment->id
        ) {
            return true;
        }

        if ($refund->reference_type === \App\Models\OrderItem::class
            && $fulfillment->order_item_id !== null
            && (int) $refund->reference_id === (int) $fulfillment->order_item_id
        ) {
            return true;
        }

        $metaFulfillmentId = (int) data_get($refund->meta, 'fulfillment_id', 0);

        return $metaFulfillmentId > 0 && $metaFulfillmentId === (int) $fulfillment->id;
    }

    /**
     * @return array{eligible: bool, confidence: string, exposure_amount: string, denial: string}
     */
    private function ineligible(string $denial): array
    {
        return [
            'eligible' => false,
            'confidence' => self::CONFIDENCE_INCOMPLETE,
            'exposure_amount' => LedgerMoney::ZERO,
            'denial' => $denial,
        ];
    }
}
