<?php

declare(strict_types=1);

namespace App\Actions\Earnings;

use App\DTOs\Earnings\CommissionDTO;
use App\DTOs\Earnings\SalespersonEarningsFilters;
use App\DTOs\Earnings\SalespersonEarningsPageDTO;
use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\CommissionClawbackStatus;
use App\Enums\CommissionStatus;
use App\Enums\FinancialDestinationType;
use App\Enums\PayoutRequestStatus;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Models\Commission;
use App\Models\CommissionClawback;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use App\Support\Commissions\CommissionClawbackDisputeState;
use App\Support\Commissions\SalespersonClawbackDebt;
use App\Support\Commissions\SalespersonCommissionEligibility;
use App\Support\LedgerMoney;
use App\Support\WalletTransactionPublicRef;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Salesperson earnings financial read model (M6.6).
 * Commission = earnings truth; WalletTransaction = posted money; pending ≠ spendable.
 */
final class GetSalespersonEarnings
{
    public function __construct(
        private readonly SalespersonCommissionEligibility $eligibility = new SalespersonCommissionEligibility,
        private readonly SalespersonClawbackDebt $clawbackDebt = new SalespersonClawbackDebt,
        private readonly CommissionClawbackDisputeState $disputeState = new CommissionClawbackDisputeState,
    ) {}

    public function handle(User $user, ?SalespersonEarningsFilters $filters = null): SalespersonEarningsPageDTO
    {
        abort_unless($user->can('view_referrals'), 403);

        $filters ??= SalespersonEarningsFilters::fromInput([]);

        $wallet = Wallet::forUser($user);
        abort_unless($wallet->type === WalletType::Customer, 403);
        abort_unless((int) $wallet->user_id === (int) $user->id, 403);

        $summary = $this->aggregateSummary($user);
        $eligibleTotal = $this->eligibility->eligiblePendingTotal($user);
        $threshold = $this->eligibility->minimumRequestThreshold();
        $payoutRequest = $this->latestPayoutRequest($user);
        $hasDebt = $this->clawbackDebt->hasOutstandingDebt($wallet);
        $debtAmount = $this->clawbackDebt->amount($wallet);

        $query = $this->baseQuery($user, $filters);
        $paginator = $query
            ->orderByDesc('id')
            ->paginate(
                perPage: $filters->perPage,
                columns: [
                    'id',
                    'order_id',
                    'fulfillment_id',
                    'salesperson_id',
                    'customer_id',
                    'order_total',
                    'commission_amount',
                    'commission_rate_percent',
                    'status',
                    'paid_at',
                    'payout_batch_id',
                    'wallet_transaction_id',
                    'created_at',
                ],
                page: $filters->page,
            );

        /** @var Collection<int, Commission> $rows */
        $rows = collect($paginator->items());
        $this->eagerLoadRowRelations($rows);

        $walletTxRefs = $this->loadOwnedWalletTransactionRefs($user, $wallet, $rows);
        $clawbacksByCommission = $this->loadClawbacksForRows($rows);
        $cutoff = now()->subDays($this->eligibility->waitDays());

        $items = $rows
            ->map(fn (Commission $commission): CommissionDTO => $this->mapRow(
                $user,
                $commission,
                $walletTxRefs,
                $clawbacksByCommission,
                $cutoff,
            ))
            ->all();

        return new SalespersonEarningsPageDTO(
            pendingTotal: $summary['pending'],
            eligibleTotal: $eligibleTotal,
            creditedTotal: $summary['credited'],
            creditedThisMonth: $summary['credited_this_month'],
            failedTotal: $summary['failed'],
            generatedTotal: $summary['generated'],
            pendingCount: $summary['pending_count'],
            creditedCount: $summary['credited_count'],
            failedCount: $summary['failed_count'],
            walletAvailableToSpend: LedgerMoney::normalize((string) $wallet->availableToSpend()),
            walletCurrency: 'USD',
            payoutThreshold: $threshold,
            waitDays: $this->eligibility->waitDays(),
            canRequestPayout: ! $hasDebt
                && $payoutRequest?->status !== PayoutRequestStatus::Pending
                && $this->eligibility->canRequestPayout($eligibleTotal),
            payoutRequestStatus: $payoutRequest?->status,
            payoutRequestEligibleAmount: $payoutRequest !== null
                ? LedgerMoney::normalize((string) $payoutRequest->eligible_amount)
                : null,
            payoutRequestCreatedAt: $payoutRequest?->created_at?->toIso8601String(),
            items: $items,
            filters: $filters,
            currentPage: $paginator->currentPage(),
            perPage: $paginator->perPage(),
            total: $paginator->total(),
            lastPage: max(1, $paginator->lastPage()),
            recentCredits: $this->recentCredits($user, $wallet),
            pricesVisible: WebsiteSetting::getPricesVisible(),
            walletDestination: new FinancialDestinationDTO(FinancialDestinationType::Wallet),
            transactionsDestination: new FinancialDestinationDTO(FinancialDestinationType::WalletTransactions),
            dashboardDestination: new FinancialDestinationDTO(FinancialDestinationType::SalespersonDashboard),
            reversedTotal: $summary['reversed'],
            waivedBackTotal: $summary['waived_back'],
            correctedBackTotal: $summary['corrected_back'],
            netCreditedTotal: $summary['net_credited'],
            outstandingClawbackDebt: $debtAmount,
            hasClawbackDebt: $hasDebt,
        );
    }

    /**
     * @return array{
     *     pending: string,
     *     credited: string,
     *     failed: string,
     *     generated: string,
     *     credited_this_month: string,
     *     pending_count: int,
     *     credited_count: int,
     *     failed_count: int,
     *     reversed: string,
     *     waived_back: string,
     *     corrected_back: string,
     *     net_credited: string
     * }
     */
    private function aggregateSummary(User $user): array
    {
        $rows = Commission::query()
            ->where('salesperson_id', $user->id)
            ->selectRaw('status, COUNT(*) as cnt, COALESCE(SUM(commission_amount), 0) as total')
            ->groupBy('status')
            ->get();

        $pending = LedgerMoney::ZERO;
        $credited = LedgerMoney::ZERO;
        $failed = LedgerMoney::ZERO;
        $pendingCount = 0;
        $creditedCount = 0;
        $failedCount = 0;

        foreach ($rows as $row) {
            $status = $row->status instanceof CommissionStatus
                ? $row->status
                : CommissionStatus::tryFrom((string) $row->status);
            $total = LedgerMoney::normalize((string) $row->total);
            $count = (int) $row->cnt;

            if ($status === CommissionStatus::Pending) {
                $pending = $total;
                $pendingCount = $count;
            } elseif ($status === CommissionStatus::Credited) {
                $credited = $total;
                $creditedCount = $count;
            } elseif ($status === CommissionStatus::Failed) {
                $failed = $total;
                $failedCount = $count;
            }
        }

        $creditedThisMonth = Commission::query()
            ->where('salesperson_id', $user->id)
            ->where('status', CommissionStatus::Credited)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [now()->startOfMonth(), now()->endOfMonth()])
            ->sum('commission_amount');

        $reversedRaw = WalletTransaction::query()
            ->where('type', WalletTransactionType::CommissionReversal)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->where('reference_type', Commission::class)
            ->whereIn('reference_id', function ($query) use ($user): void {
                $query->select('id')
                    ->from('commissions')
                    ->where('salesperson_id', $user->id);
            })
            ->sum('amount');
        $reversed = LedgerMoney::normalize((string) ($reversedRaw ?: '0'));

        $waivedRaw = WalletTransaction::query()
            ->where('type', WalletTransactionType::CommissionClawbackWaiver)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->whereIn('wallet_id', function ($query) use ($user): void {
                $query->select('id')
                    ->from('wallets')
                    ->where('user_id', $user->id);
            })
            ->sum('amount');
        $waivedBack = LedgerMoney::normalize((string) ($waivedRaw ?: '0'));

        $correctedRaw = WalletTransaction::query()
            ->where('type', WalletTransactionType::CommissionReversalCorrection)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->whereIn('wallet_id', function ($query) use ($user): void {
                $query->select('id')
                    ->from('wallets')
                    ->where('user_id', $user->id);
            })
            ->sum('amount');
        $correctedBack = LedgerMoney::normalize((string) ($correctedRaw ?: '0'));

        $netCredited = LedgerMoney::sub(
            LedgerMoney::add(
                LedgerMoney::add($credited, $waivedBack),
                $correctedBack,
            ),
            $reversed,
        );
        if (LedgerMoney::compare($netCredited, LedgerMoney::ZERO) === -1) {
            $netCredited = LedgerMoney::ZERO;
        }

        return [
            'pending' => $pending,
            'credited' => $credited,
            'failed' => $failed,
            'generated' => LedgerMoney::add(LedgerMoney::add($pending, $credited), $failed),
            'credited_this_month' => LedgerMoney::normalize((string) ($creditedThisMonth ?: '0')),
            'pending_count' => $pendingCount,
            'credited_count' => $creditedCount,
            'failed_count' => $failedCount,
            'reversed' => $reversed,
            'waived_back' => $waivedBack,
            'corrected_back' => $correctedBack,
            'net_credited' => $netCredited,
        ];
    }

    /**
     * @return Builder<Commission>
     */
    private function baseQuery(User $user, SalespersonEarningsFilters $filters): Builder
    {
        $query = Commission::query()->where('salesperson_id', $user->id);

        if ($filters->status !== 'all') {
            $query->where('status', $filters->status);
        }

        $search = $filters->search;
        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search, $user): void {
                $builder->whereHas('order', function (Builder $orderQuery) use ($search): void {
                    $orderQuery->where('order_number', 'like', $this->likePrefix($search).'%');
                });

                if (WalletTransactionPublicRef::isValidFormat($search)) {
                    $normalized = WalletTransactionPublicRef::normalize($search);
                    $builder->orWhereHas('walletTransaction', function (Builder $txQuery) use ($normalized, $user): void {
                        $txQuery
                            ->where('public_ref', $normalized)
                            ->where('type', WalletTransactionType::CommissionCredit)
                            ->whereHas('wallet', fn (Builder $walletQuery) => $walletQuery->where('user_id', $user->id));
                    });
                }
            });
        }

        return $query;
    }

    private function likePrefix(string $search): string
    {
        return str_replace(['%', '_'], ['\\%', '\\_'], $search);
    }

    /**
     * @param  Collection<int, Commission>  $rows
     */
    private function eagerLoadRowRelations(Collection $rows): void
    {
        if ($rows->isEmpty()) {
            return;
        }

        $ids = $rows->pluck('id')->all();

        $loaded = Commission::query()
            ->whereIn('id', $ids)
            ->with([
                'order:id,user_id,order_number,paid_at,status',
                'order.user:id,name,username',
                'fulfillment:id,status',
                'order.items:id,order_id',
                'order.items.fulfillments:id,order_item_id,status',
                'walletTransaction:id,wallet_id,public_ref,type,status,amount',
            ])
            ->get()
            ->keyBy('id');

        foreach ($rows as $index => $row) {
            $fresh = $loaded->get($row->id);
            if ($fresh !== null) {
                $rows[$index] = $fresh;
            }
        }
    }

    /**
     * @param  Collection<int, Commission>  $rows
     * @return array<int, string>
     */
    private function loadOwnedWalletTransactionRefs(User $user, Wallet $wallet, Collection $rows): array
    {
        $ids = $rows
            ->pluck('wallet_transaction_id')
            ->filter(fn (mixed $id): bool => is_numeric($id))
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return [];
        }

        return WalletTransaction::query()
            ->whereIn('id', $ids)
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransactionType::CommissionCredit)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->whereNotNull('public_ref')
            ->pluck('public_ref', 'id')
            ->map(fn (mixed $ref): string => (string) $ref)
            ->all();
    }

    /**
     * @param  Collection<int, Commission>  $rows
     * @return array<int, CommissionClawback>
     */
    private function loadClawbacksForRows(Collection $rows): array
    {
        $ids = $rows->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        return CommissionClawback::query()
            ->whereIn('commission_id', $ids)
            ->whereIn('status', [
                CommissionClawbackStatus::Posted,
                CommissionClawbackStatus::Waived,
                CommissionClawbackStatus::NeedsReview,
                CommissionClawbackStatus::Pending,
                CommissionClawbackStatus::Processing,
            ])
            ->with([
                'reversalWalletTransaction:id,public_ref,wallet_id,type,status,amount',
                'decisions' => function ($query): void {
                    $query->whereIn('type', [
                        \App\Enums\CommissionClawbackDecisionType::Waiver,
                        \App\Enums\CommissionClawbackDecisionType::Correction,
                    ])
                        ->with('relatedWalletTransaction:id,public_ref,amount,status,type')
                        ->orderByDesc('id');
                },
            ])
            ->orderByDesc('id')
            ->get()
            ->unique('commission_id')
            ->keyBy(fn (CommissionClawback $clawback): int => (int) $clawback->commission_id)
            ->all();
    }

    /**
     * @param  array<int, string>  $walletTxRefs
     * @param  array<int, CommissionClawback>  $clawbacksByCommission
     */
    private function mapRow(
        User $user,
        Commission $commission,
        array $walletTxRefs,
        array $clawbacksByCommission,
        CarbonInterface $cutoff,
    ): CommissionDTO {
        $amount = LedgerMoney::normalize((string) $commission->commission_amount);
        $order = $commission->order;
        $orderNumber = is_string($order?->order_number) && $order->order_number !== ''
            ? $order->order_number
            : null;

        $orderOwned = $order !== null && (int) $order->user_id > 0;
        $customerLabel = null;
        if ($orderOwned && $order->user !== null) {
            $name = trim((string) ($order->user->name ?? ''));
            $username = trim((string) ($order->user->username ?? ''));
            $customerLabel = $name !== '' ? $name : ($username !== '' ? '@'.$username : null);
            if ($customerLabel !== null && mb_strlen($customerLabel) > 80) {
                $customerLabel = mb_substr($customerLabel, 0, 77).'…';
            }
        }

        $txRef = null;
        $anomaly = false;
        if ($commission->status === CommissionStatus::Credited) {
            $txId = $commission->wallet_transaction_id !== null ? (int) $commission->wallet_transaction_id : null;
            $txRef = $txId !== null && isset($walletTxRefs[$txId]) ? $walletTxRefs[$txId] : null;

            $tx = $commission->walletTransaction;
            if ($tx === null || $txRef === null) {
                $anomaly = true;
            } elseif ($tx->type !== WalletTransactionType::CommissionCredit
                || $tx->status !== WalletTransaction::STATUS_POSTED
                || ! LedgerMoney::equals($amount, (string) $tx->amount)
            ) {
                $anomaly = true;
                $txRef = null;
            }
        } elseif ($commission->wallet_transaction_id !== null) {
            $anomaly = true;
        }

        if ($anomaly) {
            Log::warning('salesperson.earnings.integrity_anomaly', [
                'salesperson_id' => $user->id,
                'commission_id' => $commission->id,
                'status' => $commission->status?->value,
            ]);
        }

        $isEligible = $this->eligibility->isEligible($commission, $cutoff);

        $transactionDestination = ($txRef !== null && ! $anomaly)
            ? new FinancialDestinationDTO(
                FinancialDestinationType::WalletTransactionDetail,
                ['public_ref' => WalletTransactionPublicRef::normalize($txRef)]
            )
            : null;

        $orderDestination = null;

        $clawback = $clawbacksByCommission[(int) $commission->id] ?? null;
        $isFullyWaived = $clawback !== null && $clawback->status === CommissionClawbackStatus::Waived;
        $waivedCredits = LedgerMoney::ZERO;
        $correctionCredits = LedgerMoney::ZERO;
        $latestWaiverRef = null;
        $latestCorrectionRef = null;
        if ($clawback !== null) {
            foreach ($clawback->decisions as $decision) {
                $wtx = $decision->relatedWalletTransaction;
                if ($wtx === null || $wtx->status !== WalletTransaction::STATUS_POSTED) {
                    continue;
                }
                if ($wtx->type === WalletTransactionType::CommissionClawbackWaiver) {
                    $waivedCredits = LedgerMoney::add($waivedCredits, (string) $wtx->amount);
                    if ($latestWaiverRef === null && is_string($wtx->public_ref)) {
                        $latestWaiverRef = $wtx->public_ref;
                    }
                }
                if ($wtx->type === WalletTransactionType::CommissionReversalCorrection) {
                    $correctionCredits = LedgerMoney::add($correctionCredits, (string) $wtx->amount);
                    if ($latestCorrectionRef === null && is_string($wtx->public_ref)) {
                        $latestCorrectionRef = $wtx->public_ref;
                    }
                }
            }
        }
        $isUnderDisputeReview = $clawback !== null && $this->disputeState->hasActiveDispute($clawback);

        $reversedAmount = null;
        if ($clawback !== null && $clawback->reversalWalletTransaction !== null) {
            $reversedAmount = LedgerMoney::normalize((string) $clawback->reversalWalletTransaction->amount);
        }

        $isPartiallyCorrected = LedgerMoney::compare($correctionCredits, LedgerMoney::ZERO) === 1
            && $reversedAmount !== null;
        $isFullyCorrected = false;
        if ($isPartiallyCorrected && $reversedAmount !== null) {
            $remainingAfterCredits = LedgerMoney::sub($reversedAmount, LedgerMoney::add($waivedCredits, $correctionCredits));
            if (LedgerMoney::compare($remainingAfterCredits, LedgerMoney::ZERO) !== 1) {
                $isFullyCorrected = true;
                $isPartiallyCorrected = false;
            }
        }
        $isPartiallyWaived = $clawback !== null
            && $clawback->status === CommissionClawbackStatus::Posted
            && LedgerMoney::compare($waivedCredits, LedgerMoney::ZERO) === 1;
        $isFullyReversed = $clawback !== null
            && ($clawback->status === CommissionClawbackStatus::Posted || $isFullyWaived)
            && $clawback->reversal_wallet_transaction_id !== null
            && ! $isPartiallyWaived
            && ($isFullyWaived || LedgerMoney::compare($waivedCredits, LedgerMoney::ZERO) !== 1);
        // Posted with zero waivers = fully reversed; posted with partial waivers = partially waived;
        // waived status = fully waived (net collected 0).
        if ($isFullyWaived) {
            $isFullyReversed = false;
        } elseif ($isPartiallyWaived) {
            $isFullyReversed = false;
        } elseif ($isPartiallyCorrected || $isFullyCorrected) {
            $isFullyReversed = false;
        } elseif ($clawback !== null && $clawback->status === CommissionClawbackStatus::Posted) {
            $isFullyReversed = true;
        }

        $clawbackNeedsReview = $clawback !== null && $clawback->status === CommissionClawbackStatus::NeedsReview;
        $reversalRef = $clawback?->reversalWalletTransaction !== null
            && is_string($clawback->reversalWalletTransaction->public_ref)
            ? $clawback->reversalWalletTransaction->public_ref
            : null;

        $netEffect = $amount;
        if ($reversedAmount !== null) {
            $netEffect = LedgerMoney::sub(
                LedgerMoney::add(
                    LedgerMoney::add($amount, $waivedCredits),
                    $correctionCredits,
                ),
                $reversedAmount,
            );
            if (LedgerMoney::compare($netEffect, LedgerMoney::ZERO) === -1) {
                $netEffect = LedgerMoney::ZERO;
            }
        }

        $actorNext = match ($commission->status) {
            CommissionStatus::Pending => $isEligible ? 'messages.earnings_actor_staff' : 'messages.earnings_actor_wait',
            CommissionStatus::Credited => ($anomaly || $clawbackNeedsReview || $isUnderDisputeReview)
                ? 'messages.earnings_actor_support'
                : null,
            CommissionStatus::Failed => null,
        };

        return new CommissionDTO(
            stableKey: 'com:'.(string) $commission->id,
            status: $commission->status ?? CommissionStatus::Pending,
            amount: $amount,
            currency: 'USD',
            ratePercent: LedgerMoney::normalize((string) $commission->commission_rate_percent),
            orderNumber: $orderNumber,
            orderTotal: LedgerMoney::normalize((string) $commission->order_total),
            customerSafeLabel: $customerLabel,
            createdAt: $commission->created_at instanceof Carbon
                ? $commission->created_at
                : Carbon::parse((string) $commission->created_at),
            creditedAt: $commission->paid_at instanceof Carbon ? $commission->paid_at : null,
            isEligible: $isEligible,
            isIntegrityAnomaly: $anomaly,
            walletTransactionPublicRef: $txRef,
            actorNextKey: $actorNext,
            transactionDestination: $transactionDestination,
            orderDestination: $orderDestination,
            isFullyReversed: $isFullyReversed,
            clawbackPublicRef: $clawback?->public_ref,
            reversalWalletTransactionPublicRef: $reversalRef,
            reversedAmount: $reversedAmount,
            reversalTransactionDestination: $reversalRef !== null
                ? new FinancialDestinationDTO(
                    FinancialDestinationType::WalletTransactionDetail,
                    ['public_ref' => WalletTransactionPublicRef::normalize($reversalRef)]
                )
                : null,
            clawbackNeedsReview: $clawbackNeedsReview || $isUnderDisputeReview,
            isFullyWaived: $isFullyWaived,
            isPartiallyWaived: $isPartiallyWaived,
            isPartiallyCorrected: $isPartiallyCorrected,
            isFullyCorrected: $isFullyCorrected,
            isUnderDisputeReview: $isUnderDisputeReview,
            waivedAmount: LedgerMoney::compare($waivedCredits, LedgerMoney::ZERO) === 1 ? $waivedCredits : null,
            correctedAmount: LedgerMoney::compare($correctionCredits, LedgerMoney::ZERO) === 1 ? $correctionCredits : null,
            waiverWalletTransactionPublicRef: $latestWaiverRef,
            correctionWalletTransactionPublicRef: $latestCorrectionRef,
            waiverTransactionDestination: $latestWaiverRef !== null
                ? new FinancialDestinationDTO(
                    FinancialDestinationType::WalletTransactionDetail,
                    ['public_ref' => WalletTransactionPublicRef::normalize($latestWaiverRef)]
                )
                : null,
            correctionTransactionDestination: $latestCorrectionRef !== null
                ? new FinancialDestinationDTO(
                    FinancialDestinationType::WalletTransactionDetail,
                    ['public_ref' => WalletTransactionPublicRef::normalize($latestCorrectionRef)]
                )
                : null,
            netCommissionEffect: $netEffect,
        );
    }

    private function latestPayoutRequest(User $user): ?PayoutRequest
    {
        return PayoutRequest::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->first(['id', 'user_id', 'eligible_amount', 'status', 'created_at', 'processed_at']);
    }

    /**
     * @return list<array{credited_at: string, amount: string, wallet_transaction_public_ref: ?string, destination: ?FinancialDestinationDTO}>
     */
    private function recentCredits(User $user, Wallet $wallet): array
    {
        $credits = Commission::query()
            ->where('salesperson_id', $user->id)
            ->where('status', CommissionStatus::Credited)
            ->whereNotNull('paid_at')
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->limit(5)
            ->get(['id', 'commission_amount', 'paid_at', 'wallet_transaction_id']);

        $refs = $this->loadOwnedWalletTransactionRefs($user, $wallet, $credits);

        return $credits->map(function (Commission $commission) use ($refs): array {
            $txId = $commission->wallet_transaction_id !== null ? (int) $commission->wallet_transaction_id : null;
            $ref = $txId !== null && isset($refs[$txId]) ? $refs[$txId] : null;

            return [
                'credited_at' => $commission->paid_at?->toIso8601String() ?? '',
                'amount' => LedgerMoney::normalize((string) $commission->commission_amount),
                'wallet_transaction_public_ref' => $ref,
                'destination' => $ref !== null
                    ? new FinancialDestinationDTO(
                        FinancialDestinationType::WalletTransactionDetail,
                        ['public_ref' => WalletTransactionPublicRef::normalize($ref)]
                    )
                    : null,
            ];
        })->all();
    }
}
