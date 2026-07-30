<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\DTOs\Refunds\CustomerRefundDTO;
use App\DTOs\Refunds\CustomerRefundFilters;
use App\DTOs\Refunds\CustomerRefundPageDTO;
use App\Enums\CustomerRefundStatus;
use App\Enums\FinancialDestinationType;
use App\Enums\FulfillmentStatus;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use App\Support\Refunds\CustomerRefundClassifier;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Customer refund workspace list read model (M6.4).
 */
final class GetCustomerRefunds
{
    public function handle(User $user, CustomerRefundFilters $filters): CustomerRefundPageDTO
    {
        $wallet = Wallet::query()->where('user_id', $user->id)->first();

        if ($wallet === null) {
            return new CustomerRefundPageDTO(
                items: [],
                filters: $filters,
                currentPage: 1,
                perPage: $filters->perPage,
                total: 0,
                lastPage: 1,
                pricesVisible: WebsiteSetting::getPricesVisible(),
            );
        }

        $query = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransactionType::Refund);

        $this->applyStatusFilter($query, $filters->filter);
        $this->applySearch($query, $filters->search);

        $paginator = $query
            ->orderByRaw('COALESCE(posted_at, created_at) DESC')
            ->orderByDesc('id')
            ->paginate(
                perPage: $filters->perPage,
                page: $filters->page,
            );

        /** @var Collection<int, WalletTransaction> $rows */
        $rows = collect($paginator->items());
        $context = $this->loadSourceContext($rows);

        $items = $rows
            ->map(fn (WalletTransaction $tx): CustomerRefundDTO => $this->map($tx, $context))
            ->all();

        return new CustomerRefundPageDTO(
            items: $items,
            filters: $filters,
            currentPage: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: $paginator->lastPage(),
            pricesVisible: WebsiteSetting::getPricesVisible(),
        );
    }

    /**
     * @param  Builder<WalletTransaction>  $query
     */
    private function applyStatusFilter(Builder $query, string $filter): void
    {
        $failed = FulfillmentStatus::Failed->value;
        $failedFulfillmentIds = Fulfillment::query()->select('id')->where('status', $failed);

        match ($filter) {
            'under_review' => $query->where('status', WalletTransaction::STATUS_PENDING),
            'refunded' => $query->where('status', WalletTransaction::STATUS_POSTED),
            'needs_action' => $query
                ->where('status', WalletTransaction::STATUS_REJECTED)
                ->where('reference_type', Fulfillment::class)
                ->whereIn('reference_id', $failedFulfillmentIds),
            'closed' => $query
                ->where('status', WalletTransaction::STATUS_REJECTED)
                ->where(function (Builder $inner) use ($failedFulfillmentIds): void {
                    $inner
                        ->where('reference_type', '!=', Fulfillment::class)
                        ->orWhereNull('reference_type')
                        ->orWhereNotIn('reference_id', $failedFulfillmentIds);
                }),
            default => null,
        };
    }

    /**
     * @param  Builder<WalletTransaction>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '') {
            return;
        }

        $normalized = WalletTransactionPublicRef::normalize($search);
        $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $normalized);

        $query->where(function (Builder $inner) use ($escaped, $search): void {
            $inner->where('public_ref', 'like', $escaped.'%');

            $orderTerm = trim($search);
            if ($orderTerm !== '') {
                $orderEscaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $orderTerm);
                $inner->orWhere('meta->order_number', 'like', $orderEscaped.'%');
            }
        });
    }

    /**
     * @param  Collection<int, WalletTransaction>  $rows
     * @return array{
     *     fulfillments: array<int, Fulfillment>,
     *     items: array<int, OrderItem>
     * }
     */
    private function loadSourceContext(Collection $rows): array
    {
        $fulfillmentIds = $rows
            ->map(function (WalletTransaction $tx): ?int {
                if ($tx->reference_type === Fulfillment::class && is_numeric($tx->reference_id)) {
                    return (int) $tx->reference_id;
                }
                $metaId = data_get($tx->meta, 'fulfillment_id');

                return is_numeric($metaId) ? (int) $metaId : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $fulfillments = $fulfillmentIds === []
            ? collect()
            : Fulfillment::query()
                ->whereIn('id', $fulfillmentIds)
                ->get(['id', 'order_item_id', 'status'])
                ->keyBy('id');

        $orderItemIds = $rows
            ->map(function (WalletTransaction $tx) use ($fulfillments): ?int {
                $metaId = data_get($tx->meta, 'order_item_id');
                if (is_numeric($metaId)) {
                    return (int) $metaId;
                }
                $ffId = $tx->reference_type === Fulfillment::class
                    ? (int) $tx->reference_id
                    : (is_numeric(data_get($tx->meta, 'fulfillment_id')) ? (int) data_get($tx->meta, 'fulfillment_id') : null);
                if ($ffId !== null && isset($fulfillments[$ffId])) {
                    return (int) $fulfillments[$ffId]->order_item_id;
                }

                return null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $items = $orderItemIds === []
            ? collect()
            : OrderItem::query()
                ->whereIn('id', $orderItemIds)
                ->get(['id', 'name', 'quantity', 'amount_mode'])
                ->keyBy('id');

        return [
            'fulfillments' => $fulfillments->all(),
            'items' => $items->all(),
        ];
    }

    /**
     * @param  array{
     *     fulfillments: array<int, Fulfillment>,
     *     items: array<int, OrderItem>
     * }  $context
     */
    private function map(WalletTransaction $tx, array $context): CustomerRefundDTO
    {
        $ff = $this->resolveFulfillment($tx, $context['fulfillments']);
        $item = $this->resolveOrderItem($tx, $ff, $context['items']);
        $ffStatus = $ff?->status instanceof FulfillmentStatus ? $ff->status : null;
        $status = CustomerRefundClassifier::classify($tx, $ffStatus);

        // needs_action SQL filter is approximate; refine with fulfillment status.
        if ($status === CustomerRefundStatus::NeedsAction && $ffStatus !== FulfillmentStatus::Failed) {
            $status = CustomerRefundStatus::Closed;
        }

        $publicRef = filled($tx->public_ref)
            ? (string) $tx->public_ref
            : 'WTX-PENDING';

        $orderNumber = data_get($tx->meta, 'order_number');
        $orderNumber = is_string($orderNumber) && trim($orderNumber) !== '' ? trim($orderNumber) : null;

        $requestedAt = $this->parseMetaTime($tx, 'requested_at')
            ?? ($tx->created_at instanceof Carbon ? $tx->created_at : Carbon::parse((string) $tx->created_at));

        $postedAt = null;
        if ($tx->status === WalletTransaction::STATUS_POSTED && $tx->posted_at !== null) {
            $postedAt = $tx->posted_at instanceof Carbon
                ? $tx->posted_at
                : Carbon::parse((string) $tx->posted_at);
        }

        return new CustomerRefundDTO(
            stableKey: 'refund:'.$publicRef,
            publicReference: $publicRef,
            status: $status,
            amount: bcadd((string) $tx->amount, '0', 2),
            currency: strtoupper((string) (data_get($tx->meta, 'currency') ?: 'USD')),
            requestedAt: $requestedAt,
            postedAt: $postedAt,
            orderNumber: $orderNumber,
            productLabel: $item?->name,
            moneyMoved: CustomerRefundClassifier::moneyMoved($status),
            canRecover: CustomerRefundClassifier::canCustomerRecover($status),
            isIntegrityAnomaly: $status === CustomerRefundStatus::IntegrityAnomaly,
            customerSafeReason: CustomerRefundClassifier::customerSafeReason($tx),
            destination: new FinancialDestinationDTO(
                FinancialDestinationType::WalletRefundDetail,
                ['public_ref' => $publicRef]
            ),
        );
    }

    /**
     * @param  array<int, Fulfillment>  $fulfillments
     */
    private function resolveFulfillment(WalletTransaction $tx, array $fulfillments): ?Fulfillment
    {
        $id = $tx->reference_type === Fulfillment::class && is_numeric($tx->reference_id)
            ? (int) $tx->reference_id
            : (is_numeric(data_get($tx->meta, 'fulfillment_id')) ? (int) data_get($tx->meta, 'fulfillment_id') : null);

        return $id !== null ? ($fulfillments[$id] ?? null) : null;
    }

    /**
     * @param  array<int, OrderItem>  $items
     */
    private function resolveOrderItem(WalletTransaction $tx, ?Fulfillment $ff, array $items): ?OrderItem
    {
        $metaId = data_get($tx->meta, 'order_item_id');
        if (is_numeric($metaId) && isset($items[(int) $metaId])) {
            return $items[(int) $metaId];
        }

        if ($ff !== null && isset($items[(int) $ff->order_item_id])) {
            return $items[(int) $ff->order_item_id];
        }

        return null;
    }

    private function parseMetaTime(WalletTransaction $tx, string $key): ?Carbon
    {
        $value = data_get($tx->meta, $key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
