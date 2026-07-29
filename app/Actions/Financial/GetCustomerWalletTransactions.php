<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\DTOs\Financial\WalletTransactionDTO;
use App\DTOs\Financial\WalletTransactionFilters;
use App\DTOs\Financial\WalletTransactionPageDTO;
use App\Enums\FinancialDestinationType;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Models\Order;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use App\Support\TopupRequestPublicRef;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Customer posted-wallet ledger read model (M6.2).
 */
final class GetCustomerWalletTransactions
{
    /**
     * Posted types that may appear on a customer wallet ledger.
     * Settlement is excluded (platform-only by design).
     *
     * @var list<WalletTransactionType>
     */
    public const LEDGER_TYPES = [
        WalletTransactionType::Purchase,
        WalletTransactionType::Topup,
        WalletTransactionType::Refund,
        WalletTransactionType::Adjustment,
        WalletTransactionType::CommissionCredit,
    ];

    public function handle(User $user, ?WalletTransactionFilters $filters = null): WalletTransactionPageDTO
    {
        $filters ??= WalletTransactionFilters::fromInput([]);
        $wallet = Wallet::forUser($user);

        abort_unless($wallet->type === WalletType::Customer, 403);
        abort_unless((int) $wallet->user_id === (int) $user->id, 403);

        $query = $this->baseQuery($wallet, $filters);
        $paginator = $query
            ->orderByDesc('posted_at')
            ->orderByDesc('id')
            ->paginate(
                perPage: $filters->perPage,
                columns: [
                    'id',
                    'wallet_id',
                    'type',
                    'direction',
                    'amount',
                    'status',
                    'reference_type',
                    'reference_id',
                    'public_ref',
                    'posted_at',
                    'created_at',
                    'meta',
                ],
                page: $filters->page,
            );

        /** @var Collection<int, WalletTransaction> $rows */
        $rows = collect($paginator->items());
        $orderNumbersById = $this->loadOwnedOrderNumbers($user, $rows);

        $items = $rows
            ->map(fn (WalletTransaction $tx): WalletTransactionDTO => $this->mapRow($tx, $orderNumbersById))
            ->all();

        return new WalletTransactionPageDTO(
            items: $items,
            filters: $filters,
            currentPage: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: max(1, $paginator->lastPage()),
            showCommissionFilter: $user->can('view_referrals'),
            pricesVisible: WebsiteSetting::getPricesVisible(),
        );
    }

    /**
     * @return Builder<WalletTransaction>
     */
    private function baseQuery(Wallet $wallet, WalletTransactionFilters $filters): Builder
    {
        $query = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->whereIn('type', self::LEDGER_TYPES);

        if ($filters->direction === 'credit') {
            $query->where('direction', WalletTransactionDirection::Credit);
        } elseif ($filters->direction === 'debit') {
            $query->where('direction', WalletTransactionDirection::Debit);
        }

        $typeEnum = $filters->typeEnum();
        if ($typeEnum !== null) {
            $query->where('type', $typeEnum);
        }

        if ($filters->dateFrom !== null) {
            $query->whereDate('posted_at', '>=', $filters->dateFrom);
        }

        if ($filters->dateTo !== null) {
            $query->whereDate('posted_at', '<=', $filters->dateTo);
        }

        if ($filters->search !== '') {
            $this->applySearch($query, $wallet, $filters->search);
        }

        return $query;
    }

    /**
     * @param  Builder<WalletTransaction>  $query
     */
    private function applySearch(Builder $query, Wallet $wallet, string $rawSearch): void
    {
        $term = WalletTransactionPublicRef::normalize($rawSearch);
        $escaped = addcslashes($term, '%_\\');

        $query->where(function (Builder $inner) use ($wallet, $term, $escaped): void {
            $inner->where('public_ref', 'like', $escaped.'%');

            $ownedOrderIds = Order::query()
                ->where('user_id', $wallet->user_id)
                ->where('order_number', 'like', $escaped.'%')
                ->limit(50)
                ->pluck('id');

            if ($ownedOrderIds->isNotEmpty()) {
                $inner->orWhere(function (Builder $orderScope) use ($ownedOrderIds): void {
                    $orderScope
                        ->where('reference_type', Order::class)
                        ->whereIn('reference_id', $ownedOrderIds->all());
                });
            }

            // Bound meta order_number match for refunds (prefix, owned wallet already scoped).
            $inner->orWhere(function (Builder $metaScope) use ($escaped): void {
                $metaScope
                    ->whereIn('type', [WalletTransactionType::Refund, WalletTransactionType::Purchase])
                    ->where('meta->order_number', 'like', $escaped.'%');
            });

            // Exact public ref without requiring WTX- prefix typing.
            if (WalletTransactionPublicRef::isValidFormat($term)) {
                $inner->orWhere('public_ref', $term);
            }
        });
    }

    /**
     * @param  Collection<int, WalletTransaction>  $rows
     * @return array<int|string, string>
     */
    private function loadOwnedOrderNumbers(User $user, Collection $rows): array
    {
        $orderIds = $rows
            ->filter(fn (WalletTransaction $tx): bool => $tx->reference_type === Order::class)
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($orderIds === []) {
            return [];
        }

        return Order::query()
            ->where('user_id', $user->id)
            ->whereIn('id', $orderIds)
            ->pluck('order_number', 'id')
            ->all();
    }

    /**
     * @param  array<int|string, string>  $orderNumbersById
     */
    private function mapRow(WalletTransaction $tx, array $orderNumbersById): WalletTransactionDTO
    {
        $isCredit = $tx->direction === WalletTransactionDirection::Credit;
        $meta = is_array($tx->meta) ? $tx->meta : [];

        $orderNumber = $this->resolveOrderNumber($tx, $orderNumbersById, $meta);
        $destination = $this->resolveDestination($tx, $orderNumber, $meta);
        $description = $this->safeDescription($tx, $meta);
        $balanceBefore = $this->metaMoney($meta, ['balance_before', 'previous_balance']);
        $balanceAfter = $this->metaMoney($meta, ['balance_after', 'new_balance']);

        $publicRef = is_string($tx->public_ref) && $tx->public_ref !== ''
            ? $tx->public_ref
            : __('messages.financial_ledger_reference_unavailable');

        $occurredAt = $tx->posted_at instanceof Carbon
            ? $tx->posted_at
            : ($tx->created_at instanceof Carbon
                ? $tx->created_at
                : Carbon::parse((string) ($tx->posted_at ?? $tx->created_at ?? now())));

        return new WalletTransactionDTO(
            stableKey: 'wtx:'.(string) $tx->id,
            publicReference: $publicRef,
            transactionType: $tx->type,
            direction: $tx->direction,
            status: WalletTransaction::STATUS_POSTED,
            amount: bcadd((string) $tx->amount, '0', 2),
            currency: 'USD',
            occurredAt: $occurredAt,
            balanceBefore: $balanceBefore,
            balanceAfter: $balanceAfter,
            sourceType: $this->sourceTypeLabel($tx),
            sourceReference: $orderNumber,
            relatedOrderNumber: $orderNumber,
            customerSafeDescription: $description,
            destination: $destination,
            isCredit: $isCredit,
            isDebit: ! $isCredit,
        );
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<int|string, string>  $orderNumbersById
     */
    private function resolveOrderNumber(WalletTransaction $tx, array $orderNumbersById, array $meta): ?string
    {
        $fromMeta = $meta['order_number'] ?? null;
        if (is_string($fromMeta) && trim($fromMeta) !== '') {
            return trim($fromMeta);
        }

        if ($tx->reference_type === Order::class && isset($orderNumbersById[$tx->reference_id])) {
            return (string) $orderNumbersById[$tx->reference_id];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function resolveDestination(WalletTransaction $tx, ?string $orderNumber, array $meta): ?FinancialDestinationDTO
    {
        return match ($tx->type) {
            WalletTransactionType::Purchase, WalletTransactionType::Refund => $orderNumber !== null
                ? new FinancialDestinationDTO(FinancialDestinationType::OrderDetail, ['order_number' => $orderNumber])
                : new FinancialDestinationDTO(FinancialDestinationType::Orders),
            WalletTransactionType::Topup => $this->topupDestination($meta),
            WalletTransactionType::CommissionCredit => new FinancialDestinationDTO(FinancialDestinationType::SalespersonDashboard),
            WalletTransactionType::Adjustment => null,
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function topupDestination(array $meta): FinancialDestinationDTO
    {
        $topupRef = $meta['topup_public_ref'] ?? null;
        if (is_string($topupRef) && TopupRequestPublicRef::isValidFormat($topupRef)) {
            return new FinancialDestinationDTO(
                FinancialDestinationType::WalletTopupDetail,
                ['public_ref' => TopupRequestPublicRef::normalize($topupRef)]
            );
        }

        return new FinancialDestinationDTO(FinancialDestinationType::WalletTopups);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function safeDescription(WalletTransaction $tx, array $meta): ?string
    {
        if ($tx->type === WalletTransactionType::Adjustment) {
            $reason = $meta['reason'] ?? null;
            if (is_string($reason) && trim($reason) !== '') {
                return mb_substr(trim($reason), 0, 120);
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  list<string>  $keys
     */
    private function metaMoney(array $meta, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $meta[$key] ?? null;
            if (is_numeric($value)) {
                return bcadd((string) $value, '0', 2);
            }
        }

        return null;
    }

    private function sourceTypeLabel(WalletTransaction $tx): ?string
    {
        return match ($tx->type) {
            WalletTransactionType::Purchase => 'order',
            WalletTransactionType::Topup => 'topup',
            WalletTransactionType::Refund => 'refund',
            WalletTransactionType::Adjustment => 'adjustment',
            WalletTransactionType::CommissionCredit => 'commission',
            default => null,
        };
    }
}
