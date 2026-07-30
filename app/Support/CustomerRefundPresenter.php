<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\Refunds\CustomerRefundDetailDTO;
use App\DTOs\Refunds\CustomerRefundDTO;
use App\DTOs\Refunds\CustomerRefundPageDTO;
use App\Enums\CustomerRefundStatus;
use App\Models\User;

/**
 * Maps refund DTOs into passive Blade-ready arrays. No database queries.
 */
final class CustomerRefundPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function presentPage(CustomerRefundPageDTO $page, User $viewer): array
    {
        $money = FrontendMoney::for($viewer);

        return [
            'items' => array_map(
                fn (CustomerRefundDTO $item): array => $this->presentListItem($item, $money, $page->pricesVisible),
                $page->items
            ),
            'filters' => [
                'filter' => $page->filters->filter,
                'search' => $page->filters->search,
                'has_active' => $page->filters->hasActiveFilters(),
            ],
            'pagination' => [
                'current_page' => $page->currentPage,
                'per_page' => $page->perPage,
                'total' => $page->total,
                'last_page' => $page->lastPage,
                'has_pages' => $page->lastPage > 1,
            ],
            'is_empty' => $page->items === [] && ! $page->filters->hasActiveFilters(),
            'is_filtered_empty' => $page->items === [] && $page->filters->hasActiveFilters(),
            'prices_visible' => $page->pricesVisible,
            'orders_href' => route('orders.index'),
            'a11y' => [
                'region' => __('messages.refunds_region_label'),
                'search' => __('messages.refunds_search_label'),
                'filters' => __('messages.refunds_filters_label'),
                'new_available' => __('messages.refunds_updates_available'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDetail(CustomerRefundDetailDTO $detail, User $viewer, bool $pricesVisible = true): array
    {
        $money = FrontendMoney::for($viewer);

        return [
            'public_reference' => $detail->publicReference,
            'status' => $detail->status->value,
            'status_label' => $this->statusLabel($detail->status),
            'actor_label' => $this->actorLabel($detail->status),
            'badge_color' => $this->badgeColor($detail->status),
            'money_moved' => $detail->moneyMoved,
            'money_moved_label' => $detail->moneyMoved
                ? __('messages.refund_money_moved_yes')
                : __('messages.refund_money_moved_no'),
            'is_integrity_anomaly' => $detail->isIntegrityAnomaly,
            'can_recover' => $detail->canRecover,
            'amount' => [
                'raw' => $detail->amount,
                'formatted' => $pricesVisible ? $money->format($detail->amount, $detail->currency, 2) : '—',
                'dir' => 'ltr',
                'visible' => $pricesVisible,
            ],
            'currency' => $detail->currency,
            'order_number' => $detail->orderNumber,
            'product_label' => $detail->productLabel ?? __('messages.refund_product_unknown'),
            'quantity_label' => match ($detail->quantityContextKey) {
                'custom' => __('messages.refund_quantity_custom'),
                'unit_of' => __('messages.refund_quantity_unit_of', [
                    'quantity' => max(1, (int) ($detail->orderItemQuantity ?? 1)),
                ]),
                default => null,
            },
            'fulfillment_status_label' => $detail->fulfillmentStatusLabelKey !== null
                ? __('messages.'.$detail->fulfillmentStatusLabelKey)
                : null,
            'requested_at' => $detail->requestedAt->toIso8601String(),
            'requested_at_display' => $detail->requestedAt->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'reviewed_at' => $detail->reviewedAt?->toIso8601String(),
            'reviewed_at_display' => $detail->reviewedAt?->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'posted_at' => $detail->postedAt?->toIso8601String(),
            'posted_at_display' => $detail->postedAt?->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'customer_safe_reason' => $detail->customerSafeReason
                ?? ($detail->status === CustomerRefundStatus::NeedsAction
                    ? __('messages.refund_reason_fallback')
                    : null),
            'order_href' => $detail->orderDestination !== null
                ? FinancialDestinationResolver::href($detail->orderDestination)
                : null,
            'ledger_href' => $detail->ledgerDestination !== null
                ? FinancialDestinationResolver::href($detail->ledgerDestination)
                : null,
            'recovery_href' => $detail->recoveryDestination !== null
                ? FinancialDestinationResolver::href($detail->recoveryDestination)
                : null,
            'timeline' => array_map(function (array $event) {
                $occurred = $event['occurred_at'] ?? null;

                return [
                    'key' => $event['key'],
                    'label' => __('messages.'.$event['label_key']),
                    'occurred_at' => $occurred,
                    'occurred_at_display' => is_string($occurred) && $occurred !== ''
                        ? \Illuminate\Support\Carbon::parse($occurred)
                            ->timezone(config('app.timezone'))
                            ->format('M d, Y H:i')
                        : null,
                ];
            }, $detail->timeline),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentListItem(CustomerRefundDTO $item, FrontendMoney $money, bool $pricesVisible): array
    {
        return [
            'stable_key' => $item->stableKey,
            'public_reference' => $item->publicReference,
            'status' => $item->status->value,
            'status_label' => $this->statusLabel($item->status),
            'actor_label' => $this->actorLabel($item->status),
            'badge_color' => $this->badgeColor($item->status),
            'amount' => [
                'raw' => $item->amount,
                'formatted' => $pricesVisible ? $money->format($item->amount, $item->currency, 2) : '—',
                'dir' => 'ltr',
                'visible' => $pricesVisible,
            ],
            'order_number' => $item->orderNumber,
            'product_label' => $item->productLabel ?? __('messages.refund_product_unknown'),
            'money_moved' => $item->moneyMoved,
            'can_recover' => $item->canRecover,
            'is_integrity_anomaly' => $item->isIntegrityAnomaly,
            'customer_safe_reason' => $item->customerSafeReason,
            'requested_at_display' => $item->requestedAt->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'posted_at_display' => $item->postedAt?->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'href' => FinancialDestinationResolver::href($item->destination),
        ];
    }

    private function statusLabel(CustomerRefundStatus $status): string
    {
        return match ($status) {
            CustomerRefundStatus::UnderReview => __('messages.refund_status_under_review'),
            CustomerRefundStatus::Refunded => __('messages.refund_status_refunded'),
            CustomerRefundStatus::NeedsAction => __('messages.refund_status_needs_action'),
            CustomerRefundStatus::Closed => __('messages.refund_status_closed'),
            CustomerRefundStatus::IntegrityAnomaly => __('messages.refund_status_integrity_anomaly'),
        };
    }

    private function actorLabel(CustomerRefundStatus $status): string
    {
        return match ($status) {
            CustomerRefundStatus::UnderReview => __('messages.refund_actor_waiting_staff'),
            CustomerRefundStatus::Refunded => __('messages.refund_actor_completed'),
            CustomerRefundStatus::NeedsAction => __('messages.financial_status_needs_action'),
            CustomerRefundStatus::Closed => __('messages.refund_actor_closed'),
            CustomerRefundStatus::IntegrityAnomaly => __('messages.refund_actor_waiting_staff'),
        };
    }

    private function badgeColor(CustomerRefundStatus $status): string
    {
        return match ($status) {
            CustomerRefundStatus::UnderReview => 'amber',
            CustomerRefundStatus::Refunded => 'green',
            CustomerRefundStatus::NeedsAction => 'red',
            CustomerRefundStatus::Closed => 'zinc',
            CustomerRefundStatus::IntegrityAnomaly => 'amber',
        };
    }
}
