<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\Financial\CustomerTransactionDetailDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;

/**
 * Query-free presenter for customer transaction detail and printable receipt (M6.5).
 */
final class CustomerTransactionDetailPresenter
{
    /**
     * @return array<string, mixed>
     */
    public function present(CustomerTransactionDetailDTO $detail, User $viewer, bool $pricesVisible = true): array
    {
        $money = FrontendMoney::for($viewer);
        $isCredit = $detail->direction === WalletTransactionDirection::Credit;
        $sign = $isCredit ? '+' : '−';
        $amountFormatted = $pricesVisible
            ? $sign.$money->format($detail->amount, $detail->currency, 2)
            : '—';

        $sourceUnavailable = $detail->sourceTitle === null
            && $detail->sourceDescription === null
            && $detail->relatedOrderNumber === null
            && $detail->relatedTopupPublicRef === null
            && $detail->productLabel === null
            && $detail->customerSafeReason === null
            && $detail->paymentMethodName === null
            && ($detail->isIntegrityAnomaly || $detail->sourceDestination === null);

        $sourceHref = $detail->sourceDestination !== null
            ? FinancialDestinationResolver::href($detail->sourceDestination)
            : null;

        return [
            'stable_key' => $detail->stableKey,
            'public_reference' => $detail->publicReference,
            'heading' => __('messages.transaction_detail_title'),
            'type' => $detail->transactionType->value,
            'type_label' => $this->typeLabel($detail->transactionType),
            'direction' => $detail->direction->value,
            'direction_label' => $isCredit
                ? __('messages.transaction_money_in')
                : __('messages.transaction_money_out'),
            'status_label' => FinancialStatusPresentation::transactionStatusLabel(
                $detail->status,
                $detail->transactionType,
                $detail->direction
            ),
            'amount' => [
                'raw' => $detail->amount,
                'formatted' => $amountFormatted,
                'currency' => $detail->currency,
                'dir' => 'ltr',
                'visible' => $pricesVisible,
                'is_credit' => $isCredit,
            ],
            'posted_at' => $detail->postedAt->toIso8601String(),
            'posted_at_display' => $detail->postedAt->timezone(config('app.timezone'))->format('M d, Y H:i'),
            'balance_before' => $this->presentBalance($detail->balanceBefore, $detail->currency, $money, $pricesVisible),
            'balance_after' => $this->presentBalance($detail->balanceAfter, $detail->currency, $money, $pricesVisible),
            'has_balance_snapshots' => $detail->hasBalanceSnapshots,
            'balances_unavailable_label' => __('messages.transaction_balances_unavailable'),
            'source' => [
                'heading' => __('messages.transaction_source_heading'),
                'title' => $detail->sourceTitle,
                'description' => $detail->sourceDescription,
                'order_number' => $detail->relatedOrderNumber,
                'topup_public_ref' => $detail->relatedTopupPublicRef,
                'refund_public_ref' => $detail->relatedRefundPublicRef,
                'payment_method' => $detail->paymentMethodName,
                'product_label' => $detail->productLabel,
                'customer_reason' => $detail->customerSafeReason,
                'unavailable' => $sourceUnavailable && $detail->transactionType !== WalletTransactionType::Adjustment,
                'unavailable_label' => __('messages.transaction_related_unavailable'),
                'destination_label' => $this->sourceDestinationLabel($detail),
                'href' => $sourceHref,
            ],
            'timeline' => array_map(
                function (array $step): array {
                    $occurred = $step['occurred_at'] ?? null;
                    $display = is_string($occurred) && $occurred !== ''
                        ? Carbon::parse($occurred)->timezone(config('app.timezone'))->format('M d, Y H:i')
                        : null;

                    return [
                        'key' => $step['key'],
                        'label' => __($step['label_key']),
                        'occurred_at' => $occurred,
                        'occurred_at_display' => $display,
                    ];
                },
                $detail->timeline
            ),
            'actions' => [
                'print_label' => __('messages.transaction_print_receipt'),
                'print_a11y' => __('messages.transaction_print_receipt_a11y'),
                'list_href' => FinancialDestinationResolver::href($detail->listDestination),
                'list_label' => __('messages.financial_nav_transactions'),
                'source_href' => $sourceHref,
                'source_label' => $this->sourceDestinationLabel($detail),
            ],
            'receipt' => [
                'version' => $detail->receiptVersion,
                'title' => __('messages.transaction_receipt_title'),
                'brand' => config('app.name', 'İndirimGo'),
                'disclaimer' => __('messages.transaction_receipt_disclaimer'),
                'generated_on_label' => __('messages.transaction_receipt_generated_on'),
                'generated_on' => now()->timezone(config('app.timezone'))->format('M d, Y H:i'),
                'reference_label' => __('messages.transaction_reference'),
                'type_label' => __('messages.transaction_type'),
                'direction_label' => __('messages.transaction_direction'),
                'amount_label' => __('messages.transaction_amount'),
                'posted_label' => __('messages.transaction_posted_on'),
                'balance_before_label' => __('messages.transaction_balance_before'),
                'balance_after_label' => __('messages.transaction_balance_after'),
                'source_label' => __('messages.transaction_related_source'),
                'status' => WalletTransaction::STATUS_POSTED,
            ],
            'a11y' => [
                'region' => __('messages.transaction_detail_region'),
                'updated' => __('messages.financial_information_updated'),
                'facts' => __('messages.transaction_facts_heading'),
                'timeline' => __('messages.transaction_timeline_heading'),
            ],
            'is_integrity_anomaly' => $detail->isIntegrityAnomaly,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBalance(?string $raw, string $currency, FrontendMoney $money, bool $pricesVisible): array
    {
        if ($raw === null) {
            return [
                'raw' => null,
                'formatted' => null,
                'available' => false,
                'dir' => 'ltr',
            ];
        }

        return [
            'raw' => $raw,
            'formatted' => $pricesVisible ? $money->format($raw, $currency, 2) : '—',
            'available' => true,
            'dir' => 'ltr',
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

    private function sourceDestinationLabel(CustomerTransactionDetailDTO $detail): ?string
    {
        if ($detail->sourceDestination === null) {
            return null;
        }

        return match ($detail->sourceDestination->type) {
            FinancialDestinationType::OrderDetail => __('messages.transaction_view_order'),
            FinancialDestinationType::Orders => __('messages.orders'),
            FinancialDestinationType::WalletTopupDetail => __('messages.transaction_view_topup'),
            FinancialDestinationType::WalletTopups => __('messages.financial_nav_topups'),
            FinancialDestinationType::WalletRefundDetail => __('messages.transaction_view_refund'),
            FinancialDestinationType::WalletRefunds => __('messages.financial_nav_refunds'),
            FinancialDestinationType::SalespersonDashboard => __('messages.transaction_view_earnings'),
            default => null,
        };
    }
}
