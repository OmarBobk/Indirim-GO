<?php

declare(strict_types=1);

namespace App\Support\Financial;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\DTOs\Financial\RecentWalletTransactionDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Carbon;

/**
 * Latest posted wallet money movements for the financial overview preview.
 */
final class CustomerRecentWalletTransactionsReader
{
    public const LIMIT = 5;

    /**
     * @return list<RecentWalletTransactionDTO>
     */
    public function handle(User $user, Wallet $wallet): array
    {
        abort_unless((int) $wallet->user_id === (int) $user->id, 403);

        $transactions = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->whereIn('type', [
                WalletTransactionType::Purchase,
                WalletTransactionType::Topup,
                WalletTransactionType::Refund,
                WalletTransactionType::Adjustment,
                WalletTransactionType::Settlement,
                WalletTransactionType::CommissionCredit,
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get([
                'id',
                'type',
                'direction',
                'amount',
                'status',
                'meta',
                'created_at',
                'reference_type',
                'reference_id',
            ]);

        $orderIds = $transactions
            ->filter(fn (WalletTransaction $tx): bool => $tx->reference_type === Order::class)
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();

        $orderNumbersById = $orderIds === []
            ? []
            : Order::query()
                ->where('user_id', $user->id)
                ->whereIn('id', $orderIds)
                ->pluck('order_number', 'id')
                ->all();

        return $transactions
            ->map(function (WalletTransaction $tx) use ($orderNumbersById): RecentWalletTransactionDTO {
                [$label, $destination] = $this->resolveReference($tx, $orderNumbersById);

                return new RecentWalletTransactionDTO(
                    id: (string) $tx->id,
                    type: $tx->type,
                    direction: $tx->direction,
                    amount: bcadd((string) $tx->amount, '0', 2),
                    currency: 'USD',
                    status: WalletTransaction::STATUS_POSTED,
                    occurredAt: $tx->created_at instanceof Carbon
                        ? $tx->created_at
                        : Carbon::parse((string) $tx->created_at),
                    referenceLabel: $label,
                    destination: $destination,
                );
            })
            ->all();
    }

    /**
     * @param  array<int|string, string>  $orderNumbersById
     * @return array{0: ?string, 1: ?FinancialDestinationDTO}
     */
    private function resolveReference(WalletTransaction $tx, array $orderNumbersById): array
    {
        $metaOrderNumber = data_get($tx->meta, 'order_number');
        $metaOrderNumber = is_string($metaOrderNumber) && trim($metaOrderNumber) !== ''
            ? trim($metaOrderNumber)
            : null;

        if ($tx->type === WalletTransactionType::Purchase || $tx->type === WalletTransactionType::Refund) {
            $orderNumber = $metaOrderNumber;

            if ($orderNumber === null && $tx->reference_type === Order::class) {
                $orderNumber = isset($orderNumbersById[$tx->reference_id])
                    ? (string) $orderNumbersById[$tx->reference_id]
                    : null;
            }

            if ($orderNumber !== null) {
                return [
                    $orderNumber,
                    new FinancialDestinationDTO(
                        FinancialDestinationType::OrderDetail,
                        ['order_number' => $orderNumber]
                    ),
                ];
            }

            return [null, new FinancialDestinationDTO(FinancialDestinationType::Orders)];
        }

        if ($tx->type === WalletTransactionType::Topup) {
            return [null, new FinancialDestinationDTO(FinancialDestinationType::WalletTopup)];
        }

        if ($tx->type === WalletTransactionType::CommissionCredit) {
            return [null, new FinancialDestinationDTO(FinancialDestinationType::SalespersonDashboard)];
        }

        return [null, null];
    }
}
