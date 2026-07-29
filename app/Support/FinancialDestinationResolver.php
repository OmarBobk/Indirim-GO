<?php

declare(strict_types=1);

namespace App\Support;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\FinancialDestinationType;
use Illuminate\Support\Facades\Route;

/**
 * Shared typed destination → named route resolver for financial surfaces.
 */
final class FinancialDestinationResolver
{
    public static function href(FinancialDestinationDTO $destination): string
    {
        return match ($destination->type) {
            FinancialDestinationType::Wallet => route('wallet'),
            FinancialDestinationType::WalletTopup => self::walletTopupHref($destination->params),
            FinancialDestinationType::WalletTopups => route('wallet.topups.index'),
            FinancialDestinationType::WalletTopupDetail => route(
                'wallet.topups.show',
                ['topup' => (string) ($destination->params['public_ref'] ?? '')]
            ),
            FinancialDestinationType::WalletTransactions => route('wallet.transactions.index'),
            FinancialDestinationType::WalletTransactionsSearch => route(
                'wallet.transactions.index',
                ['search' => (string) ($destination->params['search'] ?? '')]
            ),
            FinancialDestinationType::TopupProof => route(
                'topup-proofs.show',
                ['proof' => (int) ($destination->params['proof_id'] ?? 0)]
            ),
            FinancialDestinationType::OrderDetail => route(
                'orders.show',
                ['order' => (string) ($destination->params['order_number'] ?? '')]
            ),
            FinancialDestinationType::Orders => route('orders.index'),
            FinancialDestinationType::Activity => Route::has('activity.index')
                ? route('activity.index')
                : route('notifications.index'),
            FinancialDestinationType::Loyalty => route('loyalty'),
            FinancialDestinationType::SalespersonDashboard => Route::has('salesperson.dashboard')
                ? route('salesperson.dashboard')
                : route('wallet'),
            FinancialDestinationType::PurchaseResume => (string) ($destination->params['url'] ?? route('wallet')),
        };
    }

    /**
     * @param  array<string, scalar|null>  $params
     */
    private static function walletTopupHref(array $params): string
    {
        $query = [];
        if (isset($params['retry']) && is_string($params['retry']) && $params['retry'] !== '') {
            $query['retry'] = $params['retry'];
        }
        if (isset($params['amount']) && is_scalar($params['amount'])) {
            $query['amount'] = (string) $params['amount'];
        }

        return $query === []
            ? route('wallet.topup')
            : route('wallet.topup', $query);
    }
}
