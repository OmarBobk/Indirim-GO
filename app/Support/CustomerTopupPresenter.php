<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\Topups\CustomerTopupDetailDTO;
use App\DTOs\Topups\CustomerTopupPageDTO;
use App\DTOs\Topups\CustomerTopupRequestDTO;
use App\Enums\TopupRequestStatus;
use App\Models\User;

/**
 * Maps top-up DTOs into passive Blade-ready arrays. No database queries.
 */
final class CustomerTopupPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function presentPage(CustomerTopupPageDTO $page, User $viewer): array
    {
        $money = FrontendMoney::for($viewer);

        return [
            'items' => array_map(
                fn (CustomerTopupRequestDTO $item): array => $this->presentListItem($item, $money, $page->pricesVisible),
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
            'pending_public_ref' => $page->pendingTopupPublicRef,
            'prices_visible' => $page->pricesVisible,
            'add_funds_href' => route('wallet.topup'),
            'a11y' => [
                'region' => __('messages.topups_region_label'),
                'search' => __('messages.topups_search_label'),
                'filters' => __('messages.topups_filters_label'),
                'new_available' => __('messages.topups_updates_available'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function presentDetail(CustomerTopupDetailDTO $detail, User $viewer, bool $pricesVisible = true): array
    {
        $money = FrontendMoney::for($viewer);

        return [
            'public_reference' => $detail->publicReference,
            'status' => $detail->status->value,
            'status_label' => $this->statusLabel($detail->status, $detail->moneyMoved, $detail->isIntegrityAnomaly),
            'actor_label' => $this->actorLabel($detail->status, $detail->moneyMoved, $detail->canRetry),
            'badge_color' => $this->badgeColor($detail->status, $detail->moneyMoved, $detail->isIntegrityAnomaly),
            'money_moved' => $detail->moneyMoved,
            'is_integrity_anomaly' => $detail->isIntegrityAnomaly,
            'can_retry' => $detail->canRetry,
            'amount' => [
                'raw' => $detail->amount,
                'formatted' => $pricesVisible ? $money->format($detail->amount, $detail->currency, 2) : '—',
                'dir' => 'ltr',
                'visible' => $pricesVisible,
            ],
            'currency' => $detail->currency,
            'payment_method_name' => $detail->paymentMethodName ?? __('messages.topup_payment_method_unknown'),
            'payment_instructions' => $detail->paymentInstructionsPlain,
            'submitted_at' => $detail->submittedAt->toIso8601String(),
            'submitted_at_display' => $detail->submittedAt->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'reviewed_at' => $detail->reviewedAt?->toIso8601String(),
            'reviewed_at_display' => $detail->reviewedAt?->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'credited_at' => $detail->creditedAt?->toIso8601String(),
            'credited_at_display' => $detail->creditedAt?->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'customer_safe_reason' => $detail->customerSafeReason,
            'has_proof' => $detail->hasProof,
            'proof_href' => $detail->proofDestination !== null
                ? FinancialDestinationResolver::href($detail->proofDestination)
                : null,
            'posted_transaction_public_ref' => $detail->postedTransactionPublicRef,
            'ledger_href' => $detail->ledgerDestination !== null
                ? FinancialDestinationResolver::href($detail->ledgerDestination)
                : null,
            'retry_href' => $detail->retryDestination !== null
                ? FinancialDestinationResolver::href($detail->retryDestination)
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
    private function presentListItem(CustomerTopupRequestDTO $item, FrontendMoney $money, bool $pricesVisible): array
    {
        return [
            'stable_key' => $item->stableKey,
            'public_reference' => $item->publicReference,
            'status' => $item->status->value,
            'status_label' => $this->statusLabel($item->status, $item->moneyMoved, $item->isIntegrityAnomaly),
            'actor_label' => $this->actorLabel($item->status, $item->moneyMoved, $item->canRetry),
            'badge_color' => $this->badgeColor($item->status, $item->moneyMoved, $item->isIntegrityAnomaly),
            'amount' => [
                'raw' => $item->amount,
                'formatted' => $pricesVisible ? $money->format($item->amount, $item->currency, 2) : '—',
                'dir' => 'ltr',
                'visible' => $pricesVisible,
            ],
            'payment_method_name' => $item->paymentMethodName ?? __('messages.topup_payment_method_unknown'),
            'has_proof' => $item->hasProof,
            'money_moved' => $item->moneyMoved,
            'can_retry' => $item->canRetry,
            'is_integrity_anomaly' => $item->isIntegrityAnomaly,
            'customer_safe_reason' => $item->customerSafeReason,
            'submitted_at_display' => $item->submittedAt->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'updated_at_display' => $item->updatedAt->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'href' => FinancialDestinationResolver::href($item->destination),
        ];
    }

    private function statusLabel(TopupRequestStatus $status, bool $moneyMoved, bool $isAnomaly): string
    {
        if ($isAnomaly) {
            return __('messages.topup_status_approved_pending_credit');
        }

        return match ($status) {
            TopupRequestStatus::Pending => __('messages.topup_status_under_review'),
            TopupRequestStatus::Approved => $moneyMoved
                ? __('messages.topup_status_credited')
                : __('messages.topup_status_approved_pending_credit'),
            TopupRequestStatus::Rejected => __('messages.topup_status_rejected'),
            TopupRequestStatus::Cancelled => __('messages.topup_status_cancelled'),
        };
    }

    private function actorLabel(TopupRequestStatus $status, bool $moneyMoved, bool $canRetry): string
    {
        return match ($status) {
            TopupRequestStatus::Pending => __('messages.topup_actor_waiting_staff'),
            TopupRequestStatus::Approved => $moneyMoved
                ? __('messages.topup_actor_completed')
                : __('messages.topup_actor_waiting_staff'),
            TopupRequestStatus::Rejected => $canRetry
                ? __('messages.financial_status_needs_action')
                : __('messages.topup_status_rejected'),
            TopupRequestStatus::Cancelled => __('messages.topup_status_cancelled'),
        };
    }

    private function badgeColor(TopupRequestStatus $status, bool $moneyMoved, bool $isAnomaly): string
    {
        if ($isAnomaly) {
            return 'amber';
        }

        return match ($status) {
            TopupRequestStatus::Pending => 'amber',
            TopupRequestStatus::Approved => $moneyMoved ? 'green' : 'amber',
            TopupRequestStatus::Rejected => 'red',
            TopupRequestStatus::Cancelled => 'zinc',
        };
    }
}
