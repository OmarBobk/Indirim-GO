<?php

declare(strict_types=1);

namespace App\Actions\Earnings;

use App\DTOs\Earnings\CommissionDTO;
use App\DTOs\Earnings\SalespersonEarningsFilters;
use App\DTOs\Earnings\SalespersonEarningsPageDTO;
use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\CommissionStatus;
use App\Enums\FinancialDestinationType;
use App\Enums\PayoutRequestStatus;
use App\Enums\WalletTransactionType;
use App\Enums\WalletType;
use App\Models\Commission;
use App\Models\PayoutRequest;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
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
        $cutoff = now()->subDays($this->eligibility->waitDays());

        $items = $rows
            ->map(fn (Commission $commission): CommissionDTO => $this->mapRow(
                $user,
                $commission,
                $walletTxRefs,
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
            canRequestPayout: $payoutRequest?->status !== PayoutRequestStatus::Pending
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
     *     failed_count: int
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

        return [
            'pending' => $pending,
            'credited' => $credited,
            'failed' => $failed,
            'generated' => LedgerMoney::add(LedgerMoney::add($pending, $credited), $failed),
            'credited_this_month' => LedgerMoney::normalize((string) ($creditedThisMonth ?: '0')),
            'pending_count' => $pendingCount,
            'credited_count' => $creditedCount,
            'failed_count' => $failedCount,
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
     * @param  array<int, string>  $walletTxRefs
     */
    private function mapRow(
        User $user,
        Commission $commission,
        array $walletTxRefs,
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

        // Salespeople do not get a weak customer order-detail shortcut from earnings.
        $orderDestination = null;

        $actorNext = match ($commission->status) {
            CommissionStatus::Pending => $isEligible ? 'messages.earnings_actor_staff' : 'messages.earnings_actor_wait',
            CommissionStatus::Credited => $anomaly ? 'messages.earnings_actor_support' : null,
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
