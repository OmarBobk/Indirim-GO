<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\DTOs\Financial\WalletTransactionDTO;
use App\DTOs\Financial\WalletTransactionPageDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;

/**
 * Maps wallet ledger DTOs into passive Blade-ready arrays. No database queries.
 */
final class CustomerWalletTransactionPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function presentPage(WalletTransactionPageDTO $page, User $viewer): array
    {
        $money = FrontendMoney::for($viewer);

        return [
            'items' => array_map(
                fn (WalletTransactionDTO $item): array => $this->present($item, $money, $page->pricesVisible),
                $page->items
            ),
            'filters' => [
                'direction' => $page->filters->direction,
                'type' => $page->filters->type,
                'search' => $page->filters->search,
                'date_from' => $page->filters->dateFrom,
                'date_to' => $page->filters->dateTo,
                'has_active' => $page->filters->hasActiveFilters(),
                'show_commission' => $page->showCommissionFilter,
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
            'a11y' => [
                'region' => __('messages.financial_ledger_region'),
                'search' => __('messages.financial_ledger_search_label'),
                'filters' => __('messages.financial_ledger_filters_label'),
                'updated' => __('messages.financial_information_updated'),
                'new_available' => __('messages.financial_ledger_new_available'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function present(WalletTransactionDTO $item, ?FrontendMoney $money = null, bool $pricesVisible = true): array
    {
        $money ??= FrontendMoney::for(null);
        $isCredit = $item->isCredit;
        $sign = $isCredit ? '+' : '−';

        return [
            'stable_key' => $item->stableKey,
            'public_reference' => $item->publicReference,
            'type' => $item->transactionType->value,
            'type_label' => $this->typeLabel($item->transactionType),
            'direction' => $item->direction->value,
            'direction_label' => FinancialStatusPresentation::directionLabel($item->direction),
            'status_label' => FinancialStatusPresentation::transactionStatusLabel(
                $item->status,
                $item->transactionType,
                $item->direction
            ),
            'amount' => [
                'raw' => $item->amount,
                'formatted' => $pricesVisible
                    ? $sign.$money->format($item->amount, $item->currency, 2)
                    : '—',
                'dir' => 'ltr',
                'visible' => $pricesVisible,
                'is_credit' => $isCredit,
            ],
            'occurred_at' => $item->occurredAt->toIso8601String(),
            'occurred_at_display' => $item->occurredAt->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'related_order_number' => $item->relatedOrderNumber,
            'description' => $item->customerSafeDescription,
            'destination_label' => $this->destinationLabel($item),
            'href' => $item->destination !== null ? $this->resolveHref($item->destination) : null,
            'icon' => $this->iconKey($item->transactionType, $item->direction),
        ];
    }

    private function typeLabel(WalletTransactionType $type): string
    {
        return match ($type) {
            WalletTransactionType::Topup => __('messages.wallet_transaction_type_topup'),
            WalletTransactionType::Purchase => __('messages.wallet_transaction_type_purchase'),
            WalletTransactionType::Refund => __('messages.wallet_transaction_type_refund'),
            WalletTransactionType::Adjustment => __('messages.financial_ledger_type_adjustment'),
            WalletTransactionType::CommissionCredit => __('messages.wallet_transaction_type_commission_credit'),
            WalletTransactionType::Settlement => __('messages.wallet_transaction_type_settlement'),
        };
    }

    private function destinationLabel(WalletTransactionDTO $item): ?string
    {
        if ($item->destination === null) {
            return null;
        }

        return match ($item->destination->type) {
            FinancialDestinationType::OrderDetail => $item->relatedOrderNumber !== null
                ? __('messages.order_number').': '.$item->relatedOrderNumber
                : __('messages.details'),
            FinancialDestinationType::Orders => __('messages.orders'),
            FinancialDestinationType::WalletTopup => __('messages.financial_track_topups'),
            FinancialDestinationType::WalletTopups => __('messages.financial_nav_topups'),
            FinancialDestinationType::WalletTopupDetail => __('messages.topup_view_request'),
            FinancialDestinationType::SalespersonDashboard => __('messages.financial_salesperson_earnings_link'),
            default => __('messages.details'),
        };
    }

    private function iconKey(WalletTransactionType $type, WalletTransactionDirection $direction): string
    {
        return match ($type) {
            WalletTransactionType::Purchase => 'shopping-bag',
            WalletTransactionType::Topup => 'banknotes',
            WalletTransactionType::Refund => 'arrow-uturn-left',
            WalletTransactionType::Adjustment => 'adjustments-horizontal',
            WalletTransactionType::CommissionCredit => 'currency-dollar',
            default => $direction === WalletTransactionDirection::Credit ? 'plus-circle' : 'minus-circle',
        };
    }

    private function resolveHref(FinancialDestinationDTO $destination): string
    {
        return FinancialDestinationResolver::href($destination);
    }
}
