<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\Admin\HistoricalCommissionExposureItemDTO;
use App\Enums\HistoricalCommissionExposureOutcome;
use App\Enums\HistoricalCommissionExposureReason;
use Illuminate\Support\Carbon;

/**
 * Query-free presenter for historical exposure rows (M7.2.4).
 */
final class AdminHistoricalCommissionExposurePresenter
{
    /**
     * @param  list<HistoricalCommissionExposureItemDTO>  $items
     * @return list<array<string, mixed>>
     */
    public function presentList(array $items): array
    {
        return array_map(fn (HistoricalCommissionExposureItemDTO $item): array => $this->presentItem($item), $items);
    }

    /**
     * @return array<string, mixed>
     */
    public function presentItem(HistoricalCommissionExposureItemDTO $item): array
    {
        return [
            'commission_id' => $item->commissionId,
            'salesperson_name' => $item->salespersonName,
            'commission_amount' => $item->commissionAmount,
            'exposure_amount' => $item->exposureAmount,
            'currency' => $item->currency,
            'order_number' => $item->orderNumber,
            'fulfillment_id' => $item->fulfillmentId,
            'credit_public_ref' => $item->creditPublicRef,
            'refund_public_ref' => $item->refundPublicRef,
            'refund_wallet_transaction_id' => $item->refundWalletTransactionId,
            'credited_at_display' => $this->formatTime($item->creditedAtIso),
            'refunded_at_display' => $this->formatTime($item->refundedAtIso),
            'confidence' => $item->confidence,
            'confidence_label' => $item->confidence === 'confirmed'
                ? __('messages.historical_exposure_confidence_confirmed')
                : __('messages.historical_exposure_confidence_incomplete'),
            'is_reviewed' => $item->isReviewed,
            'review_state_label' => $item->isReviewed
                ? __('messages.historical_exposure_reviewed_state')
                : __('messages.historical_exposure_unreviewed_state'),
            'review_outcome' => $item->reviewOutcome,
            'review_outcome_label' => $item->reviewOutcome !== null
                ? __('messages.historical_exposure_outcome_'.$item->reviewOutcome)
                : null,
            'reviewed_at_display' => $this->formatTime($item->reviewedAtIso),
            'reviewed_by_name' => $item->reviewedByName,
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function outcomeOptions(): array
    {
        return array_map(
            fn (HistoricalCommissionExposureOutcome $outcome): array => [
                'value' => $outcome->value,
                'label' => __($outcome->labelKey()),
            ],
            HistoricalCommissionExposureOutcome::cases(),
        );
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function reasonOptions(): array
    {
        return array_map(
            fn (HistoricalCommissionExposureReason $reason): array => [
                'value' => $reason->value,
                'label' => __($reason->labelKey()),
            ],
            HistoricalCommissionExposureReason::cases(),
        );
    }

    private function formatTime(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        return Carbon::parse($iso)->timezone(config('app.timezone'))->format('Y-m-d H:i');
    }
}
