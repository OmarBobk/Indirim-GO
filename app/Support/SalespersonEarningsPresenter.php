<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\Earnings\CommissionDTO;
use App\DTOs\Earnings\SalespersonEarningsPageDTO;
use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\CommissionStatus;
use App\Enums\PayoutRequestStatus;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Query-free presenter for salesperson earnings (M6.6).
 */
final class SalespersonEarningsPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(SalespersonEarningsPageDTO $page, User $viewer): array
    {
        $money = FrontendMoney::for($viewer);
        $pricesVisible = $page->pricesVisible;

        return [
            'summary' => [
                'pending' => $this->money($page->pendingTotal, $page->walletCurrency, $money, $pricesVisible),
                'eligible' => $this->money($page->eligibleTotal, $page->walletCurrency, $money, $pricesVisible),
                'credited' => $this->money($page->creditedTotal, $page->walletCurrency, $money, $pricesVisible),
                'credited_this_month' => $this->money($page->creditedThisMonth, $page->walletCurrency, $money, $pricesVisible),
                'failed' => $this->money($page->failedTotal, $page->walletCurrency, $money, $pricesVisible),
                'generated' => $this->money($page->generatedTotal, $page->walletCurrency, $money, $pricesVisible),
                'pending_count' => $page->pendingCount,
                'credited_count' => $page->creditedCount,
                'failed_count' => $page->failedCount,
                'wallet_available' => $this->money($page->walletAvailableToSpend, $page->walletCurrency, $money, $pricesVisible),
                'wallet_label' => __('messages.earnings_wallet_available'),
                'pending_not_spendable' => __('messages.earnings_pending_not_spendable'),
                'credited_in_wallet' => __('messages.earnings_credited_in_wallet'),
            ],
            'payout' => [
                'threshold' => $this->money($page->payoutThreshold, $page->walletCurrency, $money, $pricesVisible),
                'wait_days' => $page->waitDays,
                'can_request' => $page->canRequestPayout,
                'status' => $page->payoutRequestStatus?->value,
                'status_label' => $this->payoutStatusLabel($page->payoutRequestStatus),
                'request_amount' => $page->payoutRequestEligibleAmount !== null
                    ? $this->money($page->payoutRequestEligibleAmount, $page->walletCurrency, $money, $pricesVisible)
                    : null,
                'request_created_at' => $page->payoutRequestCreatedAt,
                'request_created_display' => $page->payoutRequestCreatedAt !== null
                    ? Carbon::parse($page->payoutRequestCreatedAt)->timezone(config('app.timezone'))->format('M d, Y H:i')
                    : null,
                'hint' => __('messages.earnings_payout_request_hint'),
                'confirm' => __('messages.earnings_payout_request_confirm'),
            ],
            'items' => array_map(
                fn (CommissionDTO $item): array => $this->presentCommission($item, $money, $pricesVisible),
                $page->items
            ),
            'recent_credits' => array_map(function (array $row) use ($money, $page, $pricesVisible): array {
                return [
                    'credited_at' => $row['credited_at'],
                    'credited_at_display' => $row['credited_at'] !== ''
                        ? Carbon::parse($row['credited_at'])->timezone(config('app.timezone'))->format('M d, Y H:i')
                        : '—',
                    'amount' => $this->money($row['amount'], $page->walletCurrency, $money, $pricesVisible),
                    'wallet_transaction_public_ref' => $row['wallet_transaction_public_ref'],
                    'href' => $row['destination'] instanceof FinancialDestinationDTO
                        ? FinancialDestinationResolver::href($row['destination'])
                        : null,
                ];
            }, $page->recentCredits),
            'filters' => [
                'status' => $page->filters->status,
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
            'links' => [
                'wallet_href' => FinancialDestinationResolver::href($page->walletDestination),
                'transactions_href' => FinancialDestinationResolver::href($page->transactionsDestination),
                'dashboard_href' => $page->dashboardDestination !== null
                    ? FinancialDestinationResolver::href($page->dashboardDestination)
                    : null,
            ],
            'prices_visible' => $pricesVisible,
            'a11y' => [
                'region' => __('messages.earnings_region'),
                'filters' => __('messages.earnings_filters_label'),
                'search' => __('messages.earnings_search_label'),
                'updated' => __('messages.financial_information_updated'),
                'new_available' => __('messages.earnings_updates_available'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentCommission(CommissionDTO $item, FrontendMoney $money, bool $pricesVisible): array
    {
        return [
            'stable_key' => $item->stableKey,
            'status' => $item->status->value,
            'status_label' => $this->statusLabel($item),
            'amount' => $this->money($item->amount, $item->currency, $money, $pricesVisible),
            'rate_percent' => $item->ratePercent,
            'rate_display' => rtrim(rtrim($item->ratePercent, '0'), '.').'%',
            'order_number' => $item->orderNumber,
            'order_total' => $this->money($item->orderTotal ?? '0.00', $item->currency, $money, $pricesVisible),
            'customer_label' => $item->customerSafeLabel,
            'created_at' => $item->createdAt->toIso8601String(),
            'created_at_display' => $item->createdAt->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'credited_at' => $item->creditedAt?->toIso8601String(),
            'credited_at_display' => $item->creditedAt?->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'is_eligible' => $item->isEligible,
            'eligible_label' => $item->isEligible ? __('messages.earnings_eligible_for_credit') : null,
            'is_anomaly' => $item->isIntegrityAnomaly,
            'anomaly_label' => $item->isIntegrityAnomaly ? __('messages.earnings_review_needed') : null,
            'wallet_transaction_public_ref' => $item->walletTransactionPublicRef,
            'actor_next' => $item->actorNextKey !== null ? __($item->actorNextKey) : null,
            'transaction_href' => $item->transactionDestination !== null
                ? FinancialDestinationResolver::href($item->transactionDestination)
                : null,
            'transaction_label' => __('messages.earnings_view_transaction'),
            'not_spendable_hint' => $item->status === CommissionStatus::Pending
                ? __('messages.earnings_pending_not_spendable')
                : null,
        ];
    }

    private function statusLabel(CommissionDTO $item): string
    {
        if ($item->isIntegrityAnomaly && $item->status === CommissionStatus::Credited) {
            return __('messages.earnings_status_review');
        }

        return match ($item->status) {
            CommissionStatus::Pending => __('messages.earnings_status_pending'),
            CommissionStatus::Credited => __('messages.earnings_status_credited'),
            CommissionStatus::Failed => __('messages.earnings_status_failed'),
        };
    }

    private function payoutStatusLabel(?PayoutRequestStatus $status): ?string
    {
        return match ($status) {
            PayoutRequestStatus::Pending => __('messages.earnings_payout_under_review'),
            PayoutRequestStatus::Processed => __('messages.earnings_payout_processed'),
            null => null,
        };
    }

    /**
     * @return array{raw: string, formatted: string, dir: string, visible: bool}
     */
    private function money(string $raw, string $currency, FrontendMoney $money, bool $pricesVisible): array
    {
        return [
            'raw' => $raw,
            'formatted' => $pricesVisible ? $money->format($raw, $currency, 2) : '—',
            'dir' => 'ltr',
            'visible' => $pricesVisible,
        ];
    }
}
