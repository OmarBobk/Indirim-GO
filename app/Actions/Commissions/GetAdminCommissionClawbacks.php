<?php

declare(strict_types=1);

namespace App\Actions\Commissions;

use App\DTOs\Admin\AdminCommissionClawbackListItemDTO;
use App\Enums\CommissionClawbackDecisionStatus;
use App\Enums\CommissionClawbackDecisionType;
use App\Enums\CommissionClawbackStatus;
use App\Models\CommissionClawback;
use App\Models\User;
use App\Models\Wallet;
use App\Support\CommissionClawbackPublicRef;
use App\Support\Commissions\CommissionClawbackActionRequiredQuery;
use App\Support\Commissions\CommissionClawbackFailurePresentation;
use App\Support\Commissions\CommissionClawbackRetryEligibility;
use App\Support\Commissions\CommissionClawbackWaiverArithmetic;
use App\Support\Commissions\SalespersonClawbackDebt;
use App\Support\LedgerMoney;
use App\Support\WalletTransactionPublicRef;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/**
 * Admin clawback inbox read model (M7.2.1).
 *
 * @phpstan-type Filters array{
 *     filter?: string,
 *     search?: string,
 *     policy_version?: int|null,
 *     page?: int
 * }
 */
final class GetAdminCommissionClawbacks
{
    public const PER_PAGE = 20;

    public function __construct(
        private readonly CommissionClawbackRetryEligibility $eligibility = new CommissionClawbackRetryEligibility,
        private readonly CommissionClawbackWaiverArithmetic $arithmetic = new CommissionClawbackWaiverArithmetic,
        private readonly SalespersonClawbackDebt $debt = new SalespersonClawbackDebt,
    ) {}

    /**
     * @param  Filters  $filters
     * @return array{
     *     items: list<AdminCommissionClawbackListItemDTO>,
     *     current_page: int,
     *     per_page: int,
     *     total: int,
     *     last_page: int,
     *     filter: string,
     *     search: string
     * }
     */
    public function handle(User $actor, array $filters = []): array
    {
        abort_unless($actor->can('view_commission_clawbacks'), 404);

        $filter = $this->normalizeFilter((string) ($filters['filter'] ?? 'all'));
        $search = trim((string) ($filters['search'] ?? ''));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $staleMinutes = max(1, (int) config('billing.commission_clawback.processing_stale_minutes', 30));
        $staleCutoff = now()->subMinutes($staleMinutes);

        $query = CommissionClawback::query()
            ->with([
                'salesperson:id,name,email',
                'commission:id,order_id,commission_amount,status',
                'commission.order:id,order_number',
                'refundWalletTransaction:id,public_ref',
                'originalCommissionCreditTransaction:id,public_ref',
                'reversalWalletTransaction:id,public_ref',
            ])
            ->withExists([
                'decisions as has_posted_waiver' => function (Builder $decisionQuery): void {
                    $decisionQuery->where('type', CommissionClawbackDecisionType::Waiver)
                        ->where('status', CommissionClawbackDecisionStatus::Posted)
                        ->whereNotNull('related_wallet_transaction_id');
                },
                'decisions as has_posted_correction' => function (Builder $decisionQuery): void {
                    $decisionQuery->where('type', CommissionClawbackDecisionType::Correction)
                        ->where('status', CommissionClawbackDecisionStatus::Posted)
                        ->whereNotNull('related_wallet_transaction_id');
                },
                'decisions as has_active_dispute' => function (Builder $decisionQuery): void {
                    $decisionQuery
                        ->where('type', CommissionClawbackDecisionType::DisputeOpened)
                        ->where('status', CommissionClawbackDecisionStatus::Open)
                        ->whereDoesntHave('children', function (Builder $childQuery): void {
                            $childQuery->where('type', CommissionClawbackDecisionType::DisputeResolved);
                        });
                },
            ]);

        $this->applyFilter($query, $filter, $staleCutoff);
        $this->applySearch($query, $search);

        $query
            ->orderByRaw($this->prioritySql($staleCutoff))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        /** @var LengthAwarePaginator<int, CommissionClawback> $paginator */
        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);

        $debtBySalesperson = $this->debtFlagsForPage(collect($paginator->items()));

        $items = [];
        foreach ($paginator->items() as $clawback) {
            $decision = $this->eligibility->decide($clawback);
            $failure = CommissionClawbackFailurePresentation::present($clawback->failure_code);
            $debtState = $debtBySalesperson[(int) $clawback->salesperson_id] ?? [
                'outstanding' => false,
                'recovered' => false,
            ];

            $hasActiveDispute = (bool) ($clawback->has_active_dispute ?? false);
            $hasPostedCorrection = (bool) ($clawback->has_posted_correction ?? false);
            $netCollected = $this->arithmetic->netCollected($clawback);
            $remainingCorrectable = $this->arithmetic->remainingCorrectable($clawback);
            $isNetCollectedZero = $clawback->reversal_wallet_transaction_id !== null
                && LedgerMoney::compare($netCollected, LedgerMoney::ZERO) !== 1;
            $isPartiallyCorrected = $hasPostedCorrection
                && LedgerMoney::compare($remainingCorrectable, LedgerMoney::ZERO) === 1;
            $isFullyCorrected = $hasPostedCorrection
                && LedgerMoney::compare($remainingCorrectable, LedgerMoney::ZERO) !== 1;

            $items[] = new AdminCommissionClawbackListItemDTO(
                publicRef: (string) $clawback->public_ref,
                status: $clawback->status instanceof CommissionClawbackStatus
                    ? $clawback->status->value
                    : (string) $clawback->status,
                amount: LedgerMoney::normalize((string) $clawback->amount),
                currency: strtoupper((string) ($clawback->currency ?: 'USD')),
                salespersonName: $clawback->salesperson?->name,
                salespersonEmail: $clawback->salesperson?->email,
                salespersonId: (int) $clawback->salesperson_id,
                orderNumber: $clawback->commission?->order?->order_number,
                refundPublicRef: $clawback->refundWalletTransaction?->public_ref,
                originalCreditPublicRef: $clawback->originalCommissionCreditTransaction?->public_ref,
                reversalPublicRef: $clawback->reversalWalletTransaction?->public_ref,
                failureCode: $clawback->failure_code,
                failureCategory: $failure['category'],
                isRetryable: $decision->allowed,
                isStale: $decision->isStale,
                isActionRequired: CommissionClawbackActionRequiredQuery::isActionRequired(
                    $clawback->status,
                    $decision->isStale,
                    is_string($clawback->failure_code) ? $clawback->failure_code : null,
                    $hasActiveDispute,
                ),
                hasOutstandingDebt: $debtState['outstanding'],
                debtRecovered: $debtState['recovered'],
                policyVersion: (int) $clawback->policy_version,
                createdAtIso: $clawback->created_at?->toIso8601String() ?? '',
                attemptedAtIso: $clawback->attempted_at?->toIso8601String(),
                postedAtIso: $clawback->posted_at?->toIso8601String(),
                lastRetryAtIso: $clawback->last_retry_at?->toIso8601String(),
                isPartiallyWaived: $clawback->status === CommissionClawbackStatus::Posted
                    && (bool) ($clawback->has_posted_waiver ?? false),
                isDisputed: $hasActiveDispute,
                isPartiallyCorrected: $isPartiallyCorrected,
                isFullyCorrected: $isFullyCorrected,
                isCorrectionAvailable: $clawback->reversal_wallet_transaction_id !== null
                    && LedgerMoney::compare($remainingCorrectable, LedgerMoney::ZERO) === 1,
                isNetCollectedZero: $isNetCollectedZero,
            );
        }

        return [
            'items' => $items,
            'current_page' => $paginator->currentPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
            'last_page' => max(1, $paginator->lastPage()),
            'filter' => $filter,
            'search' => $search,
        ];
    }

    /**
     * @param  Builder<CommissionClawback>  $query
     */
    private function applyFilter(Builder $query, string $filter, CarbonInterface $staleCutoff): void
    {
        match ($filter) {
            'needs_review' => $query->where('status', CommissionClawbackStatus::NeedsReview),
            'retryable' => $query->where(function (Builder $builder) use ($staleCutoff): void {
                $builder->where(function (Builder $inner) use ($staleCutoff): void {
                    $inner->where('status', CommissionClawbackStatus::Processing)
                        ->where(function (Builder $stale) use ($staleCutoff): void {
                            $stale->whereNull('attempted_at')
                                ->orWhere('attempted_at', '<=', $staleCutoff);
                        })
                        ->whereNull('reversal_wallet_transaction_id');
                })->orWhere(function (Builder $inner): void {
                    $inner->whereIn('status', [
                        CommissionClawbackStatus::NeedsReview,
                        CommissionClawbackStatus::Failed,
                    ])->whereIn('failure_code', CommissionClawbackFailurePresentation::RETRYABLE_CODES);
                })->orWhere('status', CommissionClawbackStatus::Pending);
            }),
            'stale_processing' => $query->where('status', CommissionClawbackStatus::Processing)
                ->whereNull('reversal_wallet_transaction_id')
                ->where(function (Builder $builder) use ($staleCutoff): void {
                    $builder->whereNull('attempted_at')
                        ->orWhere('attempted_at', '<=', $staleCutoff);
                }),
            'pending' => $query->where('status', CommissionClawbackStatus::Pending),
            'processing' => $query->where('status', CommissionClawbackStatus::Processing),
            'posted' => $query->where('status', CommissionClawbackStatus::Posted),
            'waived' => $query->where('status', CommissionClawbackStatus::Waived),
            'partially_waived' => $query->where('status', CommissionClawbackStatus::Posted)
                ->whereHas('decisions', function (Builder $decisionQuery): void {
                    $decisionQuery->where('type', CommissionClawbackDecisionType::Waiver)
                        ->where('status', CommissionClawbackDecisionStatus::Posted)
                        ->whereNotNull('related_wallet_transaction_id');
                }),
            'disputed' => $query->whereHas('decisions', function (Builder $decisionQuery): void {
                $decisionQuery
                    ->where('type', CommissionClawbackDecisionType::DisputeOpened)
                    ->where('status', CommissionClawbackDecisionStatus::Open)
                    ->whereDoesntHave('children', function (Builder $childQuery): void {
                        $childQuery->where('type', CommissionClawbackDecisionType::DisputeResolved);
                    });
            }),
            'correction_available' => null,
            'partially_corrected' => null,
            'fully_corrected' => null,
            'net_collected_zero' => null,
            'failed' => $query->where('status', CommissionClawbackStatus::Failed),
            'debt_outstanding', 'debt_recovered' => null,
            default => null,
        };

        if ($filter === 'correction_available') {
            $query->whereNotNull('reversal_wallet_transaction_id')
                ->where('status', '!=', CommissionClawbackStatus::Waived)
                ->whereRaw($this->netCollectedSql().' > 0');
        }

        if ($filter === 'partially_corrected') {
            $query->whereHas('decisions', function (Builder $decisionQuery): void {
                $decisionQuery->where('type', CommissionClawbackDecisionType::Correction)
                    ->where('status', CommissionClawbackDecisionStatus::Posted)
                    ->whereNotNull('related_wallet_transaction_id');
            })->whereRaw($this->netCollectedSql().' > 0');
        }

        if ($filter === 'fully_corrected') {
            $query->whereHas('decisions', function (Builder $decisionQuery): void {
                $decisionQuery->where('type', CommissionClawbackDecisionType::Correction)
                    ->where('status', CommissionClawbackDecisionStatus::Posted)
                    ->whereNotNull('related_wallet_transaction_id');
            })->whereRaw($this->netCollectedSql().' <= 0');
        }

        if ($filter === 'net_collected_zero') {
            $query->whereNotNull('reversal_wallet_transaction_id')
                ->whereRaw($this->netCollectedSql().' <= 0');
        }

        // Debt filters applied after pagination would be wrong — apply via salesperson wallet subquery.
        if ($filter === 'debt_outstanding') {
            $query->whereHas('salesperson', function (Builder $userQuery): void {
                $userQuery->whereHas('wallet', function (Builder $walletQuery): void {
                    $walletQuery->where('balance', '<', 0);
                });
            })->where('status', CommissionClawbackStatus::Posted);
        }

        if ($filter === 'debt_recovered') {
            $query->where('status', CommissionClawbackStatus::Posted)
                ->whereHas('salesperson', function (Builder $userQuery): void {
                    $userQuery->whereHas('wallet', function (Builder $walletQuery): void {
                        $walletQuery->where('balance', '>=', 0);
                    });
                });
        }
    }

    /**
     * @param  Builder<CommissionClawback>  $query
     */
    private function applySearch(Builder $query, string $search): void
    {
        if ($search === '' || mb_strlen($search) < 3) {
            return;
        }

        $normalized = strtoupper($search);

        $query->where(function (Builder $builder) use ($search, $normalized): void {
            if (CommissionClawbackPublicRef::isValidFormat($normalized)) {
                $builder->orWhere('public_ref', CommissionClawbackPublicRef::normalize($normalized));
            }

            if (WalletTransactionPublicRef::isValidFormat($normalized)) {
                $wtx = WalletTransactionPublicRef::normalize($normalized);
                $builder->orWhereHas('refundWalletTransaction', fn (Builder $q) => $q->where('public_ref', $wtx))
                    ->orWhereHas('originalCommissionCreditTransaction', fn (Builder $q) => $q->where('public_ref', $wtx))
                    ->orWhereHas('reversalWalletTransaction', fn (Builder $q) => $q->where('public_ref', $wtx));
            }

            $builder->orWhereHas('commission.order', function (Builder $orderQuery) use ($search): void {
                $orderQuery->where('order_number', 'like', $this->likePrefix($search).'%');
            });
        });
    }

    private function prioritySql(CarbonInterface $staleCutoff): string
    {
        $cutoff = $staleCutoff->toDateTimeString();
        $retryable = "'".implode("','", CommissionClawbackFailurePresentation::RETRYABLE_CODES)."'";

        return <<<SQL
CASE
  WHEN status = 'needs_review' AND failure_code IN ({$retryable}) THEN 0
  WHEN status = 'failed' AND failure_code IN ({$retryable}) THEN 0
  WHEN EXISTS (
    SELECT 1 FROM commission_clawback_decisions d
    WHERE d.commission_clawback_id = commission_clawbacks.id
      AND d.type = 'dispute_opened'
      AND d.status = 'open'
      AND NOT EXISTS (
        SELECT 1 FROM commission_clawback_decisions r
        WHERE r.parent_decision_id = d.id AND r.type = 'dispute_resolved'
      )
  ) THEN 1
  WHEN status = 'processing' AND reversal_wallet_transaction_id IS NULL AND (attempted_at IS NULL OR attempted_at <= '{$cutoff}') THEN 2
  WHEN status = 'needs_review' THEN 3
  WHEN status = 'failed' THEN 3
  WHEN status = 'pending' THEN 4
  WHEN status = 'processing' THEN 5
  WHEN status = 'posted' THEN 6
  WHEN status = 'waived' THEN 7
  ELSE 8
END
SQL;
    }

    private function netCollectedSql(): string
    {
        return <<<'SQL'
COALESCE((
    SELECT COALESCE(reversal.amount, 0) - COALESCE(credits.total, 0)
    FROM wallet_transactions reversal
    LEFT JOIN (
        SELECT d.commission_clawback_id AS clawback_id, SUM(wt.amount) AS total
        FROM commission_clawback_decisions d
        INNER JOIN wallet_transactions wt ON wt.id = d.related_wallet_transaction_id
        WHERE d.status = 'posted'
          AND wt.status = 'posted'
          AND wt.type IN ('commission_clawback_waiver', 'commission_reversal_correction')
        GROUP BY d.commission_clawback_id
    ) credits ON credits.clawback_id = commission_clawbacks.id
    WHERE reversal.id = commission_clawbacks.reversal_wallet_transaction_id
), 0)
SQL;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, CommissionClawback>  $rows
     * @return array<int, array{outstanding: bool, recovered: bool}>
     */
    private function debtFlagsForPage($rows): array
    {
        $salespersonIds = $rows->pluck('salesperson_id')->unique()->filter()->map(fn ($id) => (int) $id)->all();
        if ($salespersonIds === []) {
            return [];
        }

        $wallets = Wallet::query()
            ->whereIn('user_id', $salespersonIds)
            ->get(['id', 'user_id', 'balance'])
            ->keyBy(fn (Wallet $wallet): int => (int) $wallet->user_id);

        $result = [];
        foreach ($salespersonIds as $salespersonId) {
            $wallet = $wallets->get($salespersonId);
            if ($wallet === null) {
                $result[$salespersonId] = ['outstanding' => false, 'recovered' => false];

                continue;
            }

            $hasDebt = $this->debt->hasOutstandingDebt($wallet);
            $hasReversalEvidence = $this->debt->hasPostedReversalEvidence($wallet);
            $result[$salespersonId] = [
                'outstanding' => $hasDebt,
                'recovered' => $hasReversalEvidence && ! $hasDebt,
            ];
        }

        return $result;
    }

    private function normalizeFilter(string $filter): string
    {
        $allowed = [
            'all',
            'needs_review',
            'retryable',
            'stale_processing',
            'pending',
            'processing',
            'posted',
            'waived',
            'partially_waived',
            'disputed',
            'correction_available',
            'partially_corrected',
            'fully_corrected',
            'net_collected_zero',
            'failed',
            'debt_outstanding',
            'debt_recovered',
        ];

        return in_array($filter, $allowed, true) ? $filter : 'all';
    }

    private function likePrefix(string $search): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $search);
    }
}
