<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\Financial\FinancialBalanceDTO;
use App\DTOs\Financial\FinancialDestinationDTO;
use App\DTOs\Financial\FinancialOverviewDTO;
use App\DTOs\Financial\PendingFinancialItemDTO;
use App\DTOs\Financial\RecentWalletTransactionDTO;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Maps FinancialOverviewDTO into passive Blade-ready arrays. No database queries.
 */
final class CustomerFinancialPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(FinancialOverviewDTO $overview, User $viewer): array
    {
        $money = FrontendMoney::for($viewer);
        $pricesVisible = $overview->pricesVisible;

        return [
            'balance' => $this->presentBalance($overview->balance, $money, $pricesVisible),
            'actions' => [
                'can_add_funds' => $overview->canAddFunds,
                'add_funds_href' => route('wallet.topup'),
                'purchase_resume_url' => $overview->purchaseResumeUrl,
                'track_topups_href' => route('wallet.topups.index'),
                'track_refunds_href' => route('wallet.refunds.index'),
                'view_transactions_href' => route('wallet.transactions.index'),
                'loyalty_href' => route('loyalty'),
                'salesperson_href' => $overview->showSalespersonLink && Route::has('salesperson.dashboard')
                    ? route('salesperson.dashboard')
                    : null,
                'show_salesperson_link' => $overview->showSalespersonLink && Route::has('salesperson.dashboard'),
            ],
            'pending' => [
                'items' => array_map(
                    fn (PendingFinancialItemDTO $item): array => $this->presentPendingItem($item, $money, $pricesVisible),
                    $overview->pendingItems
                ),
                'has_more' => $overview->pendingHasMore,
                'counts' => $overview->pendingCounts,
                'view_all_href' => $this->pendingViewAllHref($overview),
                'is_empty' => $overview->pendingItems === [],
            ],
            'recent' => [
                'items' => array_map(
                    fn (RecentWalletTransactionDTO $item): array => $this->presentRecent($item, $money, $pricesVisible),
                    $overview->recentTransactions
                ),
                'is_empty' => $overview->recentTransactions === [],
            ],
            'prices_visible' => $pricesVisible,
            'a11y' => [
                'balance_region' => __('messages.financial_overview_balance_region'),
                'updated' => __('messages.financial_information_updated'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBalance(FinancialBalanceDTO $balance, FrontendMoney $money, bool $pricesVisible): array
    {
        $format = static fn (?string $amount): ?array => $amount === null
            ? null
            : [
                'raw' => $amount,
                'formatted' => $pricesVisible ? $money->format($amount, $balance->currency, 2) : '—',
                'dir' => 'ltr',
                'visible' => $pricesVisible,
            ];

        return [
            'available_to_spend' => $format($balance->availableToSpend),
            'prepaid_balance' => $format($balance->prepaidBalance),
            'outstanding_debt' => $balance->hasOutstandingDebt ? $format($balance->outstandingDebt) : null,
            'credit_limit' => $balance->creditFacilityActive ? $format($balance->creditLimit) : null,
            'remaining_credit' => $balance->creditFacilityActive ? $format($balance->remainingCredit) : null,
            'credit_facility_active' => $balance->creditFacilityActive,
            'has_outstanding_debt' => $balance->hasOutstandingDebt,
            'currency' => $balance->currency,
            'labels' => [
                'available' => __('messages.wallet_available_to_spend'),
                'prepaid' => __('messages.wallet_prepaid_balance'),
                'debt' => __('messages.wallet_you_owe'),
                'credit_limit' => __('messages.wallet_credit_limit_label'),
                'remaining_credit' => __('messages.wallet_available_credit_label'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentPendingItem(PendingFinancialItemDTO $item, FrontendMoney $money, bool $pricesVisible): array
    {
        return [
            'id' => $item->id,
            'kind' => $item->kind,
            'actor' => $item->actor->value,
            'actor_label' => FinancialStatusPresentation::pendingActorLabel($item->actor),
            'badge_color' => FinancialStatusPresentation::pendingActorBadgeColor($item->actor),
            'title' => __('messages.'.$item->titleKey),
            'amount' => $item->amount !== null
                ? [
                    'raw' => $item->amount,
                    'formatted' => $pricesVisible ? $money->format($item->amount, $item->currency, 2) : '—',
                    'dir' => 'ltr',
                    'visible' => $pricesVisible,
                ]
                : null,
            'occurred_at' => $item->occurredAt->toIso8601String(),
            'occurred_at_display' => $item->occurredAt->diffForHumans(),
            'reference_label' => $item->referenceLabel,
            'customer_safe_reason' => $item->customerSafeReason,
            'href' => $this->resolveHref($item->destination),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentRecent(RecentWalletTransactionDTO $item, FrontendMoney $money, bool $pricesVisible): array
    {
        $isCredit = $item->direction === WalletTransactionDirection::Credit;
        $sign = $isCredit ? '+' : '−';

        return [
            'id' => $item->id,
            'type' => $item->type->value,
            'type_label' => $this->typeLabel($item->type),
            'direction' => $item->direction->value,
            'direction_label' => FinancialStatusPresentation::directionLabel($item->direction),
            'status_label' => FinancialStatusPresentation::transactionStatusLabel(
                $item->status,
                $item->type,
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
            'reference_label' => $item->referenceLabel,
            'public_reference' => $item->publicReference,
            'href' => $item->destination !== null ? $this->resolveHref($item->destination) : null,
        ];
    }

    private function typeLabel(WalletTransactionType $type): string
    {
        return match ($type) {
            WalletTransactionType::Topup => __('messages.wallet_transaction_type_topup'),
            WalletTransactionType::Purchase => __('messages.wallet_transaction_type_purchase'),
            WalletTransactionType::Refund => __('messages.wallet_transaction_type_refund'),
            WalletTransactionType::Adjustment => __('messages.wallet_transaction_type_adjustment'),
            WalletTransactionType::Settlement => __('messages.wallet_transaction_type_settlement'),
            WalletTransactionType::CommissionCredit => __('messages.wallet_transaction_type_commission_credit'),
        };
    }

    private function resolveHref(FinancialDestinationDTO $destination): string
    {
        return FinancialDestinationResolver::href($destination);
    }

    private function pendingViewAllHref(FinancialOverviewDTO $overview): ?string
    {
        if (! $overview->pendingHasMore) {
            return null;
        }

        if (($overview->pendingCounts['pending_topups'] ?? 0) > 0) {
            return route('wallet.topups.index');
        }

        if (($overview->pendingCounts['pending_refunds'] ?? 0) > 0) {
            return route('wallet.refunds.index');
        }

        if (($overview->pendingCounts['needs_customer_action'] ?? 0) > 0) {
            return Route::has('activity.index')
                ? route('activity.index', ['filter' => 'action_required'])
                : route('wallet.refunds.index');
        }

        return Route::has('activity.index')
            ? route('activity.index', ['filter' => 'action_required', 'category' => 'orders'])
            : route('orders.index');
    }
}
