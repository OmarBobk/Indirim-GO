<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\DTOs\Admin\HistoricalCommissionExposureItemDTO;
use App\Enums\CommissionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Commission;
use App\Models\Fulfillment;
use App\Models\HistoricalCommissionExposureReview;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\Commissions\HistoricalCommissionExposureClassifier;
use App\Support\LedgerMoney;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Read-only historical commission exposure report (M7.2.4).
 * Never creates clawbacks or wallet transactions.
 *
 * @phpstan-type Filters array{
 *     filter?: string,
 *     search?: string,
 *     salesperson_id?: int|null,
 *     refund_from?: string|null,
 *     refund_to?: string|null,
 *     page?: int
 * }
 */
final class GetHistoricalCommissionExposure
{
    public const PER_PAGE = 20;

    public const DEFAULT_LOOKBACK_MONTHS = 24;

    public function __construct(
        private readonly HistoricalCommissionExposureClassifier $classifier = new HistoricalCommissionExposureClassifier,
    ) {}

    /**
     * @param  Filters  $filters
     * @return array{
     *     items: list<HistoricalCommissionExposureItemDTO>,
     *     current_page: int,
     *     per_page: int,
     *     total: int,
     *     last_page: int,
     *     filter: string,
     *     search: string,
     *     refund_from: string,
     *     refund_to: ?string,
     *     summary: array{confirmed_unreviewed_count: int, incomplete_unreviewed_count: int, reviewed_count: int, confirmed_exposure_total: string}
     * }
     */
    public function handle(User $actor, array $filters = []): array
    {
        abort_unless($actor->can('view_historical_commission_exposure'), 404);

        $filter = $this->normalizeFilter((string) ($filters['filter'] ?? 'unreviewed'));
        $search = trim((string) ($filters['search'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $salespersonId = isset($filters['salesperson_id']) && is_numeric($filters['salesperson_id'])
            ? (int) $filters['salesperson_id']
            : null;

        [$refundFrom, $refundTo] = $this->resolveDateWindow(
            isset($filters['refund_from']) ? (string) $filters['refund_from'] : null,
            isset($filters['refund_to']) ? (string) $filters['refund_to'] : null,
        );

        $base = $this->candidateQuery($refundFrom, $refundTo);

        if ($salespersonId !== null && $salespersonId > 0) {
            $base->where('commissions.salesperson_id', $salespersonId);
        }

        $this->applySearch($base, $search);
        $this->applyReviewFilter($base, $filter);
        $this->applyConfidenceFilter($base, $filter);

        $total = (clone $base)->count();
        $rows = (clone $base)
            ->orderByRaw($this->prioritySql())
            ->orderByDesc('refunds.posted_at')
            ->orderByDesc('commissions.id')
            ->orderByDesc('refunds.id')
            ->forPage($page, self::PER_PAGE)
            ->get();

        $commissionIds = $rows->pluck('commission_id')->map(fn ($id) => (int) $id)->unique()->all();
        $refundIds = $rows->pluck('refund_id')->map(fn ($id) => (int) $id)->unique()->all();

        $reviews = HistoricalCommissionExposureReview::query()
            ->whereIn('commission_id', $commissionIds !== [] ? $commissionIds : [0])
            ->whereIn('refund_wallet_transaction_id', $refundIds !== [] ? $refundIds : [0])
            ->with('reviewer:id,name')
            ->get()
            ->keyBy(fn (HistoricalCommissionExposureReview $review): string => $review->commission_id.':'.$review->refund_wallet_transaction_id);

        $items = [];
        foreach ($rows as $row) {
            $review = $reviews->get(((int) $row->commission_id).':'.((int) $row->refund_id));
            $items[] = new HistoricalCommissionExposureItemDTO(
                commissionId: (int) $row->commission_id,
                salespersonId: (int) $row->salesperson_id,
                salespersonName: is_string($row->salesperson_name ?? null) ? $row->salesperson_name : null,
                commissionAmount: LedgerMoney::normalize((string) $row->commission_amount),
                currency: 'USD',
                orderNumber: is_string($row->order_number ?? null) ? $row->order_number : null,
                fulfillmentId: $row->fulfillment_id !== null ? (int) $row->fulfillment_id : null,
                creditPublicRef: is_string($row->credit_public_ref ?? null) ? $row->credit_public_ref : null,
                refundPublicRef: is_string($row->refund_public_ref ?? null) ? $row->refund_public_ref : null,
                refundWalletTransactionId: (int) $row->refund_id,
                creditedAtIso: $row->credited_at !== null ? Carbon::parse((string) $row->credited_at)->toIso8601String() : null,
                refundedAtIso: $row->refunded_at !== null ? Carbon::parse((string) $row->refunded_at)->toIso8601String() : null,
                exposureAmount: LedgerMoney::normalize((string) $row->exposure_amount),
                confidence: (string) $row->confidence,
                isReviewed: $review !== null,
                reviewOutcome: $review?->outcome instanceof \App\Enums\HistoricalCommissionExposureOutcome
                    ? $review->outcome->value
                    : (is_string($review?->outcome) ? $review->outcome : null),
                reviewedAtIso: $review?->reviewed_at?->toIso8601String(),
                reviewedByName: $review?->reviewer?->name,
            );
        }

        $summary = $this->summaryCounts($refundFrom, $refundTo);

        return [
            'items' => $items,
            'current_page' => $page,
            'per_page' => self::PER_PAGE,
            'total' => $total,
            'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
            'filter' => $filter,
            'search' => $search,
            'refund_from' => $refundFrom->toDateString(),
            'refund_to' => $refundTo?->toDateString(),
            'summary' => $summary,
        ];
    }

    /**
     * Revalidate a single commission/refund pair for the Review Action.
     *
     * @return array{eligible: bool, confidence: string, exposure_amount: string, denial: string, commission: ?Commission, refund: ?WalletTransaction}
     */
    public function revalidatePair(int $commissionId, int $refundId): array
    {
        $commission = Commission::query()->with(['fulfillment', 'walletTransaction'])->find($commissionId);
        $refund = WalletTransaction::query()->find($refundId);

        if ($commission === null || $refund === null) {
            return [
                'eligible' => false,
                'confidence' => HistoricalCommissionExposureClassifier::CONFIDENCE_INCOMPLETE,
                'exposure_amount' => LedgerMoney::ZERO,
                'denial' => 'missing_source',
                'commission' => $commission,
                'refund' => $refund,
            ];
        }

        $classified = $this->classifier->classify(
            $commission,
            $refund,
            $commission->walletTransaction,
            $commission->fulfillment,
        );

        return array_merge($classified, [
            'commission' => $commission,
            'refund' => $refund,
        ]);
    }

    /**
     * @return Builder<\Illuminate\Database\Eloquent\Model>
     */
    private function candidateQuery(Carbon $refundFrom, ?Carbon $refundTo): Builder
    {
        $fulfillmentClass = Fulfillment::class;
        $orderItemClass = OrderItem::class;
        $effectiveAt = $this->classifier->policyEffectiveAt();
        $creditType = WalletTransactionType::CommissionCredit->value;
        $refundType = WalletTransactionType::Refund->value;
        $reversalType = WalletTransactionType::CommissionReversal->value;
        $posted = WalletTransaction::STATUS_POSTED;

        $query = Commission::query()
            ->from('commissions')
            ->select([
                'commissions.id as commission_id',
                'commissions.salesperson_id',
                'commissions.fulfillment_id',
                'commissions.commission_amount',
                'commissions.paid_at as credited_at',
                'commissions.wallet_transaction_id',
                'users.name as salesperson_name',
                'orders.order_number',
                'credits.public_ref as credit_public_ref',
                'refunds.id as refund_id',
                'refunds.public_ref as refund_public_ref',
                'refunds.posted_at as refunded_at',
                DB::raw('commissions.commission_amount as exposure_amount'),
                DB::raw($this->confidenceSql().' as confidence'),
            ])
            ->join('users', 'users.id', '=', 'commissions.salesperson_id')
            ->leftJoin('orders', 'orders.id', '=', 'commissions.order_id')
            ->leftJoin('wallet_transactions as credits', function ($join) use ($creditType, $posted): void {
                $join->on('credits.id', '=', 'commissions.wallet_transaction_id')
                    ->where('credits.type', $creditType)
                    ->where('credits.status', $posted);
            })
            ->join('wallet_transactions as refunds', function ($join) use ($fulfillmentClass, $orderItemClass, $refundType, $posted): void {
                $join->where('refunds.type', $refundType)
                    ->where('refunds.status', $posted)
                    ->where(function ($refundJoin) use ($fulfillmentClass, $orderItemClass): void {
                        $refundJoin->where(function ($byFulfillment) use ($fulfillmentClass): void {
                            $byFulfillment->where('refunds.reference_type', $fulfillmentClass)
                                ->whereColumn('refunds.reference_id', 'commissions.fulfillment_id');
                        })->orWhere(function ($byItem) use ($orderItemClass): void {
                            $byItem->where('refunds.reference_type', $orderItemClass)
                                ->whereExists(function ($exists): void {
                                    $exists->select(DB::raw('1'))
                                        ->from('fulfillments')
                                        ->whereColumn('fulfillments.id', 'commissions.fulfillment_id')
                                        ->whereColumn('fulfillments.order_item_id', 'refunds.reference_id');
                                });
                        });
                    });
            })
            ->where('commissions.status', CommissionStatus::Credited->value)
            ->whereNotNull('commissions.fulfillment_id')
            ->where('refunds.posted_at', '>=', $refundFrom->toDateTimeString())
            ->whereNotExists(function ($exists): void {
                $exists->select(DB::raw('1'))
                    ->from('commission_clawbacks')
                    ->whereColumn('commission_clawbacks.commission_id', 'commissions.id')
                    ->whereColumn('commission_clawbacks.refund_wallet_transaction_id', 'refunds.id');
            })
            ->whereNotExists(function ($exists) use ($reversalType, $posted): void {
                $exists->select(DB::raw('1'))
                    ->from('wallet_transactions as reversals')
                    ->where('reversals.type', $reversalType)
                    ->where('reversals.status', $posted)
                    ->whereRaw('reversals.idempotency_key = '.$this->reversalKeyExpression());
            });

        if ($refundTo !== null) {
            $query->where('refunds.posted_at', '<=', $refundTo->copy()->endOfDay()->toDateTimeString());
        }

        if ($effectiveAt !== null) {
            $query->where('refunds.posted_at', '<', $effectiveAt->toDateTimeString());
        }

        return $query;
    }

    private function confidenceSql(): string
    {
        // Candidate join already proves refund↔fulfillment; confirmed requires a valid credit link.
        return "CASE WHEN credits.id IS NOT NULL THEN 'confirmed' ELSE 'incomplete' END";
    }

    private function prioritySql(): string
    {
        return <<<'SQL'
CASE
  WHEN reviews.id IS NULL AND credits.id IS NOT NULL THEN 0
  WHEN reviews.id IS NULL THEN 1
  ELSE 2
END
SQL;
    }

    private function reversalKeyExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => "('commission_reversal:' || commissions.id || ':refund:' || refunds.id)",
            default => "CONCAT('commission_reversal:', commissions.id, ':refund:', refunds.id)",
        };
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyReviewFilter(Builder $query, string $filter): void
    {
        $query->leftJoin('historical_commission_exposure_reviews as reviews', function ($join): void {
            $join->on('reviews.commission_id', '=', 'commissions.id')
                ->on('reviews.refund_wallet_transaction_id', '=', 'refunds.id');
        });

        // Fix prioritySql dependency — already joined.
        if ($filter === 'unreviewed') {
            $query->whereNull('reviews.id');
        } elseif ($filter === 'reviewed') {
            $query->whereNotNull('reviews.id');
        }
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applyConfidenceFilter(Builder $query, string $filter): void
    {
        if ($filter === 'confirmed') {
            $query->whereRaw('('.$this->confidenceSql().") = 'confirmed'");
        } elseif ($filter === 'incomplete') {
            $query->whereRaw('('.$this->confidenceSql().") = 'incomplete'");
        }
    }

    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '' || mb_strlen($search) < 3) {
            return;
        }

        $normalized = strtoupper($search);
        $query->where(function (Builder $builder) use ($search, $normalized): void {
            if (ctype_digit($search)) {
                $builder->orWhere('commissions.id', (int) $search);
            }

            if (WalletTransactionPublicRef::isValidFormat($normalized)) {
                $ref = WalletTransactionPublicRef::normalize($normalized);
                $builder->orWhere('credits.public_ref', $ref)
                    ->orWhere('refunds.public_ref', $ref);
            }

            $builder->orWhere('orders.order_number', 'like', $this->likePrefix($search).'%');
        });
    }

    /**
     * @return array{confirmed_unreviewed_count: int, incomplete_unreviewed_count: int, reviewed_count: int, confirmed_exposure_total: string}
     */
    private function summaryCounts(Carbon $refundFrom, ?Carbon $refundTo): array
    {
        $confirmedUnreviewed = (clone $this->candidateQuery($refundFrom, $refundTo))
            ->leftJoin('historical_commission_exposure_reviews as reviews', function ($join): void {
                $join->on('reviews.commission_id', '=', 'commissions.id')
                    ->on('reviews.refund_wallet_transaction_id', '=', 'refunds.id');
            })
            ->whereNull('reviews.id')
            ->whereRaw('('.$this->confidenceSql().") = 'confirmed'")
            ->count();

        $incompleteUnreviewed = (clone $this->candidateQuery($refundFrom, $refundTo))
            ->leftJoin('historical_commission_exposure_reviews as reviews', function ($join): void {
                $join->on('reviews.commission_id', '=', 'commissions.id')
                    ->on('reviews.refund_wallet_transaction_id', '=', 'refunds.id');
            })
            ->whereNull('reviews.id')
            ->whereRaw('('.$this->confidenceSql().") = 'incomplete'")
            ->count();

        $reviewed = (clone $this->candidateQuery($refundFrom, $refundTo))
            ->join('historical_commission_exposure_reviews as reviews', function ($join): void {
                $join->on('reviews.commission_id', '=', 'commissions.id')
                    ->on('reviews.refund_wallet_transaction_id', '=', 'refunds.id');
            })
            ->count();

        $confirmedTotalRaw = (clone $this->candidateQuery($refundFrom, $refundTo))
            ->leftJoin('historical_commission_exposure_reviews as reviews', function ($join): void {
                $join->on('reviews.commission_id', '=', 'commissions.id')
                    ->on('reviews.refund_wallet_transaction_id', '=', 'refunds.id');
            })
            ->whereNull('reviews.id')
            ->whereRaw('('.$this->confidenceSql().") = 'confirmed'")
            ->sum('commissions.commission_amount');

        return [
            'confirmed_unreviewed_count' => $confirmedUnreviewed,
            'incomplete_unreviewed_count' => $incompleteUnreviewed,
            'reviewed_count' => $reviewed,
            'confirmed_exposure_total' => LedgerMoney::normalize((string) ($confirmedTotalRaw ?: '0')),
        ];
    }

    /**
     * @return array{0: Carbon, 1: ?Carbon}
     */
    private function resolveDateWindow(?string $from, ?string $to): array
    {
        $refundTo = null;
        if (is_string($to) && trim($to) !== '') {
            try {
                $refundTo = Carbon::parse(trim($to))->endOfDay();
            } catch (\Throwable) {
                $refundTo = null;
            }
        }

        if (is_string($from) && trim($from) !== '') {
            try {
                $refundFrom = Carbon::parse(trim($from))->startOfDay();
            } catch (\Throwable) {
                $refundFrom = now()->subMonths(self::DEFAULT_LOOKBACK_MONTHS)->startOfDay();
            }
        } else {
            $refundFrom = now()->subMonths(self::DEFAULT_LOOKBACK_MONTHS)->startOfDay();
        }

        return [$refundFrom, $refundTo];
    }

    private function normalizeFilter(string $filter): string
    {
        $allowed = ['unreviewed', 'reviewed', 'confirmed', 'incomplete', 'all'];

        return in_array($filter, $allowed, true) ? $filter : 'unreviewed';
    }

    private function likePrefix(string $search): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $search);
    }
}
