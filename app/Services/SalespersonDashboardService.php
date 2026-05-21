<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\CommissionStatus;
use App\Enums\FulfillmentStatus;
use App\Models\Commission;
use App\Models\User;
use App\Models\WebsiteSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SalespersonDashboardService
{
    /**
     * @return array{
     *     orders: array<int, array<string, mixed>>,
     *     customers: array<int, array<string, mixed>>,
     *     referredUsers: array<int, array<string, mixed>>,
     *     kpis: array<int, array<string, mixed>>,
     *     earningsSummary: array<int, array<string, mixed>>,
     *     payoutHistory: array<int, array<string, mixed>>,
     *     payout: array<string, mixed>,
     *     chartSeries: array<string, mixed>,
     *     chartPresets: array<string, array<string, mixed>>
     * }
     */
    public function build(
        int $salespersonId,
        string $dateRange = '30d',
        string $chartFilter = 'this_month',
        ?string $chartStartDate = null,
        ?string $chartEndDate = null
    ): array {
        $waitDays = WebsiteSetting::getCommissionPayoutWaitDays();
        $cutoff = now()->subDays($waitDays);

        $commissions = $this->loadCommissionsWithPayoutRelations($salespersonId);

        $chartPresets = $this->buildChartPresetSeries($salespersonId);
        $chartSeries = in_array($chartFilter, ['today', '7d', 'this_month', 'ytd'], true)
            ? $chartPresets[$chartFilter]
            : $this->buildChartSeries($salespersonId, $chartFilter, $chartStartDate, $chartEndDate);

        $orders = $commissions->map(function (Commission $commission): array {
            $order = $commission->order;
            $customer = $order?->user;

            if ($customer === null) {
                $customerName = '—';
                $customerPhone = '';
                $customerUsername = '';
                $customerSearchLine = '—';
            } else {
                $customerName = (string) ($customer->name ?? '—');
                $customerPhone = trim((string) ($customer->country_code ?? '').' '.(string) ($customer->phone ?? ''));
                $customerUsername = trim((string) ($customer->username ?? ''));
                $customerSearchLine = trim(
                    $customerName.' '.$customerPhone.' '.$customerUsername
                );
            }

            return [
                'order' => $order?->order_number ?? '#'.$commission->order_id,
                'date' => ($order?->created_at ?? now())->format('Y-m-d H:i:s'),
                'product' => $this->productSummary($commission),
                'sale' => (float) $commission->order_total,
                'commission' => (float) $commission->commission_amount,
                'commission_status' => $commission->status?->value ?? CommissionStatus::Pending->value,
                'fulfillment_status' => $this->fulfillmentStatusValue($commission),
                /** @deprecated Kept for search / debugging; use customer_name, customer_phone, customer_username in UI */
                'customer' => $customerSearchLine,
                'customer_name' => $customerName,
                'customer_phone' => $customerPhone,
                'customer_username' => $customerUsername,
            ];
        })->values()->all();

        $paidThisMonth = (float) $commissions
            ->where('status', CommissionStatus::Credited)
            ->filter(fn (Commission $commission): bool => $commission->paid_at?->between(now()->startOfMonth(), now()->endOfMonth()) ?? false)
            ->sum('commission_amount');
        $pending = (float) $commissions
            ->where('status', CommissionStatus::Pending)
            ->sum('commission_amount');

        $sl = $chartSeries['sparkline'];
        $bucketCount = max(1, count($chartSeries['labels'] ?? []));
        $creditedInRange = (float) array_sum(array_map(fn ($v): float => (float) $v, $chartSeries['earnings'] ?? []));
        $ordersInRange = (int) array_sum(array_map(fn ($v): int => (int) $v, $chartSeries['orders'] ?? []));
        $pendingInRange = (float) array_sum(array_map(fn ($v): float => (float) $v, $chartSeries['pending'] ?? []));
        $avgDailyCredited = $creditedInRange / $bucketCount;

        $kpis = [
            ['key' => 'earnings', 'label' => __('messages.dashboard_kpi_lbl_credited_range'), 'hint' => __('messages.dashboard_kpi_hint_chart_range'), 'value' => $creditedInRange, 'delta' => 0.0, 'spark' => $sl['earnings']],
            ['key' => 'paid', 'label' => __('messages.dashboard_kpi_lbl_avg_daily_credited'), 'hint' => __('messages.dashboard_kpi_hint_chart_range'), 'value' => $avgDailyCredited, 'delta' => 0.0, 'spark' => $sl['earnings']],
            ['key' => 'orders', 'label' => __('messages.dashboard_kpi_lbl_orders_range'), 'hint' => __('messages.dashboard_kpi_hint_chart_range'), 'value' => (float) $ordersInRange, 'delta' => 0.0, 'spark' => $sl['orders']],
            ['key' => 'pending', 'label' => __('messages.dashboard_kpi_lbl_pending_range'), 'hint' => __('messages.dashboard_kpi_hint_chart_range'), 'value' => $pendingInRange, 'delta' => 0.0, 'spark' => $sl['pending']],
        ];

        $customers = Commission::query()
            ->select('customer_id', DB::raw('COUNT(*) as orders_count'), DB::raw('SUM(commission_amount) as commission_sum'))
            ->where('salesperson_id', $salespersonId)
            ->groupBy('customer_id')
            ->with('customer:id,name,email')
            ->orderByDesc('commission_sum')
            ->limit(500)
            ->get()
            ->map(fn (Commission $commission): array => [
                'name' => $commission->customer?->name ?? '—',
                'email' => (string) ($commission->customer?->email ?? ''),
                'orders' => (int) ($commission->orders_count ?? 0),
                'commission' => (float) ($commission->commission_sum ?? 0),
            ])
            ->values()
            ->all();

        $referredUsers = $this->loadReferredUsersForDashboard($salespersonId);

        $paidTotal = (float) $commissions
            ->where('status', CommissionStatus::Credited)
            ->sum('commission_amount');
        $eligible = $this->sumEligiblePendingForPayout($commissions, $cutoff);

        $earningsSummary = [
            ['label' => __('messages.total_earned_this_month'), 'value' => $paidThisMonth],
            ['label' => __('messages.total_paid_to_date'), 'value' => $paidTotal],
            ['label' => __('messages.pending_balance'), 'value' => $pending],
            ['label' => __('messages.eligible_for_payout'), 'value' => $eligible],
        ];

        $payoutHistory = $commissions
            ->where('status', CommissionStatus::Credited)
            ->sortByDesc(fn (Commission $commission): int => $commission->paid_at?->timestamp ?? 0)
            ->take(10)
            ->map(fn (Commission $commission): array => [
                'date' => $commission->paid_at?->format('Y-m-d H:i') ?? '—',
                'amount' => (float) $commission->commission_amount,
                'method' => $commission->paid_method ?? 'wallet',
            ])
            ->values()
            ->all();

        $lastPaidDisplay = '—';
        if ($payoutHistory !== []) {
            $firstDate = (string) ($payoutHistory[0]['date'] ?? '');
            if ($firstDate !== '' && $firstDate !== '—') {
                try {
                    $lastPaidDisplay = Carbon::parse($firstDate)->format('m-d');
                } catch (\Throwable) {
                    $lastPaidDisplay = strlen($firstDate) >= 5 ? substr($firstDate, 5, 5) : '—';
                }
            }
        }

        return [
            'orders' => $orders,
            'customers' => $customers,
            'referredUsers' => $referredUsers,
            'kpis' => $kpis,
            'earningsSummary' => $earningsSummary,
            'payoutHistory' => $payoutHistory,
            'payout' => [
                'eligible' => $eligible,
                'threshold' => WebsiteSetting::getCommissionPayoutMinAmount(),
                'pending' => $pending,
                'days_left' => $waitDays,
                'next_date' => now()->addDays($waitDays)->toDateString(),
                'last_paid' => $lastPaidDisplay,
                'segments' => 8,
            ],
            'chartSeries' => $chartSeries,
            'chartPresets' => $chartPresets,
        ];
    }

    /**
     * Every account referred by this salesperson (not only those with commissions).
     *
     * @return array<int, array<string, mixed>>
     */
    private function loadReferredUsersForDashboard(int $salespersonId): array
    {
        return User::query()
            ->where('referred_by_user_id', $salespersonId)
            ->with('roles:id,name')
            ->orderByDesc('created_at')
            ->limit(2000)
            ->get()
            ->map(function (User $u): array {
                $rolesLabel = $u->roles
                    ->pluck('name')
                    ->map(function ($roleName): string {
                        $name = (string) $roleName;

                        return \Illuminate\Support\Facades\Lang::has('messages.role_'.$name)
                            ? (string) __('messages.role_'.$name)
                            : str_replace('_', ' ', \Illuminate\Support\Str::headline($name));
                    })
                    ->implode(', ') ?: '—';

                return [
                    'id' => $u->id,
                    'name' => (string) ($u->name ?? ''),
                    'username' => (string) ($u->username ?? ''),
                    'email' => (string) ($u->email ?? ''),
                    'phone' => trim(implode(' ', array_filter([(string) ($u->country_code ?? ''), (string) ($u->phone ?? '')]))),
                    'active' => $u->blocked_at === null,
                    'last_login_at' => $u->last_login_at?->format('Y-m-d H:i'),
                    'created_at' => $u->created_at?->format('Y-m-d') ?? '',
                    'roles_label' => $rolesLabel,
                ];
            })
            ->values()
            ->all();
    }

    /** Same eligible rules as the payout card (pending, past wait window, fulfillment completed). */
    public function eligiblePendingPayoutTotal(int $salespersonId): float
    {
        $cutoff = now()->subDays(WebsiteSetting::getCommissionPayoutWaitDays());

        return $this->sumEligiblePendingForPayout($this->loadCommissionsWithPayoutRelations($salespersonId), $cutoff);
    }

    /**
     * @return Collection<int, Commission>
     */
    private function loadCommissionsWithPayoutRelations(int $salespersonId): Collection
    {
        return Commission::query()
            ->where('salesperson_id', $salespersonId)
            ->with([
                'order:id,user_id,order_number,created_at,paid_at',
                'order.user:id,name,username,phone,country_code',
                'order.items:id,order_id,name',
                'order.items.fulfillments:id,order_item_id,status',
                'fulfillment:id,status',
            ])
            ->latest('id')
            ->get();
    }

    /**
     * @param  Collection<int, Commission>  $commissions
     */
    private function sumEligiblePendingForPayout(Collection $commissions, CarbonInterface $cutoff): float
    {
        return (float) $commissions
            ->filter(fn (Commission $commission): bool => $commission->status === CommissionStatus::Pending
                && ($commission->order?->paid_at?->lessThanOrEqualTo($cutoff) ?? false)
                && $this->fulfillmentStatusValue($commission) === FulfillmentStatus::Completed->value)
            ->sum('commission_amount');
    }

    /**
     * Cached chart payloads for fixed ranges (no custom). Switching tabs reads from this array — no extra DB.
     *
     * @return array<string, array<string, mixed>>
     */
    public function buildChartPresetSeries(int $salespersonId): array
    {
        $out = [];
        foreach (['today', '7d', 'this_month', 'ytd'] as $key) {
            $out[$key] = $this->buildChartSeries($salespersonId, $key, null, null);
        }

        return $out;
    }

    /**
     * @return array{
     *     labels: array<int, string>,
     *     earnings: array<int, float>,
     *     orders: array<int, int>,
     *     pending: array<int, float>,
     *     rangeLabel: string,
     *     sparkline: array{earnings: array<int, float>, orders: array<int, float>, pending: array<int, float>}
     * }
     */
    public function buildChartSeries(
        int $salespersonId,
        string $chartFilter = 'this_month',
        ?string $chartStartDate = null,
        ?string $chartEndDate = null
    ): array {
        [$start, $end, $dates, $label] = $this->resolveChartRange($chartFilter, $chartStartDate, $chartEndDate);

        $earningsByDate = Commission::query()
            ->selectRaw('DATE(paid_at) as day, SUM(commission_amount) as total')
            ->where('salesperson_id', $salespersonId)
            ->where('status', CommissionStatus::Credited)
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end])
            ->groupBy('day')
            ->pluck('total', 'day');

        $ordersByDate = Commission::query()
            ->join('orders', 'orders.id', '=', 'commissions.order_id')
            ->selectRaw('DATE(orders.created_at) as day, COUNT(DISTINCT commissions.order_id) as total')
            ->where('commissions.salesperson_id', $salespersonId)
            ->whereBetween('orders.created_at', [$start, $end])
            ->groupBy('day')
            ->pluck('total', 'day');

        $pendingByDate = Commission::query()
            ->join('orders', 'orders.id', '=', 'commissions.order_id')
            ->selectRaw('DATE(orders.paid_at) as day, SUM(commissions.commission_amount) as total')
            ->where('commissions.salesperson_id', $salespersonId)
            ->where('commissions.status', CommissionStatus::Pending)
            ->whereNotNull('orders.paid_at')
            ->whereBetween('orders.paid_at', [$start, $end])
            ->groupBy('day')
            ->pluck('total', 'day');

        $points = $dates->map(function ($day) use ($earningsByDate, $ordersByDate, $pendingByDate): array {
            $key = $day->toDateString();

            return [
                'date' => $key,
                'label' => $day->translatedFormat('M j'),
                'earnings' => (float) ($earningsByDate[$key] ?? 0),
                'orders' => (int) ($ordersByDate[$key] ?? 0),
                'pending' => (float) ($pendingByDate[$key] ?? 0),
            ];
        })->values();

        $dayCount = $dates->count();
        $points = $this->bucketChartPointsForReadableAxis($points, $dayCount);

        $earnings = $points->pluck('earnings')->map(fn (float $value): float => (float) number_format($value, 2, '.', ''))->all();
        $orders = $points->pluck('orders')->all();
        $pending = $points->pluck('pending')->map(fn (float $value): float => (float) number_format($value, 2, '.', ''))->all();

        return [
            'labels' => $points->pluck('label')->all(),
            'earnings' => $earnings,
            'orders' => $orders,
            'pending' => $pending,
            'rangeLabel' => $label,
            'sparkline' => [
                'earnings' => $this->sparklineSlice($earnings),
                'orders' => $this->sparklineSlice($orders),
                'pending' => $this->sparklineSlice($pending),
            ],
        ];
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: Collection<int, Carbon>, 3: string}
     */
    private function resolveChartRange(string $chartFilter, ?string $chartStartDate, ?string $chartEndDate): array
    {
        $todayStart = now()->startOfDay();
        $todayEnd = now()->endOfDay();

        if ($chartFilter === 'today') {
            $start = $todayStart;
            $end = $todayEnd;
            $label = __('messages.today');

            return [$start, $end, collect([$todayStart]), $label];
        }

        if ($chartFilter === 'custom') {
            $start = $chartStartDate !== null ? Carbon::parse($chartStartDate)->startOfDay() : now()->subDays(29)->startOfDay();
            $end = $chartEndDate !== null ? Carbon::parse($chartEndDate)->endOfDay() : now()->endOfDay();

            if ($start->greaterThan($end)) {
                [$start, $end] = [$end->copy()->startOfDay(), $start->copy()->endOfDay()];
            }

            $daysCount = max(1, (int) $start->diffInDays($end) + 1);
            $dates = collect(range(0, $daysCount - 1))
                ->map(fn (int $offset) => $start->copy()->addDays($offset)->startOfDay())
                ->values();

            $label = $start->toDateString().' - '.$end->toDateString();

            return [$start, $end, $dates, $label];
        }

        if ($chartFilter === '7d') {
            $start = now()->subDays(6)->startOfDay();
            $end = now()->endOfDay();
            $dates = collect(range(0, 6))
                ->map(fn (int $offset) => $start->copy()->addDays($offset)->startOfDay())
                ->values();

            return [$start, $end, $dates, '7d'];
        }

        if ($chartFilter === 'ytd') {
            $start = now()->startOfYear()->startOfDay();
            $end = now()->endOfDay();
            $daysCount = max(1, (int) $start->diffInDays($end) + 1);
            $dates = collect(range(0, $daysCount - 1))
                ->map(fn (int $offset) => $start->copy()->addDays($offset)->startOfDay())
                ->values();

            return [$start, $end, $dates, 'YTD'];
        }

        $start = now()->startOfMonth()->startOfDay();
        $end = now()->endOfDay();
        $daysCount = max(1, (int) $start->diffInDays($end) + 1);
        $dates = collect(range(0, $daysCount - 1))
            ->map(fn (int $offset) => $start->copy()->addDays($offset)->startOfDay())
            ->values();

        return [$start, $end, $dates, __('messages.this_month')];
    }

    /**
     * @return array{0: Carbon, 1: Collection<int, Carbon>}
     */
    private function resolveRange(string $dateRange): array
    {
        $start = match ($dateRange) {
            '7d' => now()->subDays(6)->startOfDay(),
            'ytd' => now()->startOfYear()->startOfDay(),
            default => now()->subDays(29)->startOfDay(),
        };

        $daysCount = match ($dateRange) {
            '7d' => 7,
            'ytd' => min((int) now()->startOfYear()->diffInDays(now()) + 1, 366),
            default => 30,
        };

        $dates = collect(range($daysCount - 1, 0))
            ->map(fn (int $offset) => now()->startOfDay()->subDays($offset))
            ->values();

        return [$start, $dates];
    }

    /**
     * Long ranges (YTD, wide custom) need fewer x-axis buckets than daily points — industry practice is to
     * aggregate to the time unit you can label legibly (week or month), not one tick per day.
     *
     * @param  Collection<int, array{date: string, label: string, earnings: float, orders: int, pending: float}>  $dailyPoints
     * @return Collection<int, array{label: string, earnings: float, orders: int, pending: float}>
     */
    private function bucketChartPointsForReadableAxis(Collection $dailyPoints, int $dayCount): Collection
    {
        if ($dayCount <= 42) {
            return $dailyPoints->map(fn (array $row): array => [
                'label' => $row['label'],
                'earnings' => $row['earnings'],
                'orders' => $row['orders'],
                'pending' => $row['pending'],
            ])->values();
        }

        if ($dayCount <= 120) {
            return $this->bucketPointsWeekly($dailyPoints);
        }

        return $this->bucketPointsMonthly($dailyPoints);
    }

    /**
     * @param  Collection<int, array{date: string, label: string, earnings: float, orders: int, pending: float}>  $points
     * @return Collection<int, array{label: string, earnings: float, orders: int, pending: float}>
     */
    private function bucketPointsWeekly(Collection $points): Collection
    {
        return $points->groupBy(function (array $row): string {
            return Carbon::parse($row['date'])->copy()->startOfWeek()->toDateString();
        })->map(function (Collection $rows, string $weekStart): array {
            $d = Carbon::parse($weekStart)->locale(app()->getLocale());

            return [
                'label' => (string) $d->translatedFormat('M j'),
                'earnings' => (float) number_format($rows->sum('earnings'), 2, '.', ''),
                'orders' => (int) $rows->sum('orders'),
                'pending' => (float) number_format($rows->sum('pending'), 2, '.', ''),
            ];
        })->values();
    }

    /**
     * @param  Collection<int, array{date: string, label: string, earnings: float, orders: int, pending: float}>  $points
     * @return Collection<int, array{label: string, earnings: float, orders: int, pending: float}>
     */
    private function bucketPointsMonthly(Collection $points): Collection
    {
        $firstYear = Carbon::parse($points->first()['date'])->year;
        $lastYear = Carbon::parse($points->last()['date'])->year;
        $showYearOnLabel = $firstYear !== $lastYear;

        return $points->groupBy(function (array $row): string {
            return Carbon::parse($row['date'])->format('Y-n');
        })->map(function (Collection $rows, string $ym) use ($showYearOnLabel): array {
            [$y, $n] = explode('-', $ym, 2);
            $d = Carbon::createFromDate((int) $y, (int) $n, 1)->locale(app()->getLocale());
            $format = $showYearOnLabel ? 'M Y' : 'M';

            return [
                'label' => (string) $d->translatedFormat($format),
                'earnings' => (float) number_format($rows->sum('earnings'), 2, '.', ''),
                'orders' => (int) $rows->sum('orders'),
                'pending' => (float) number_format($rows->sum('pending'), 2, '.', ''),
            ];
        })->values();
    }

    /**
     * @param  array<int, int|float>  $series
     * @return array<int, float>
     */
    private function sparklineSlice(array $series): array
    {
        return collect($series)
            ->take(-10)
            ->map(fn (int|float $value): float => (float) $value)
            ->pad(10, 0.0)
            ->values()
            ->all();
    }

    private function fulfillmentStatusValue(Commission $commission): string
    {
        if ($commission->fulfillment !== null) {
            return $commission->fulfillment->status->value;
        }

        $statuses = collect($commission->order?->items ?? [])
            ->flatMap(fn ($item) => $item->fulfillments ?? [])
            ->pluck('status')
            ->map(fn ($status) => $status instanceof FulfillmentStatus ? $status->value : (string) $status);

        if ($statuses->contains(FulfillmentStatus::Failed->value)) {
            return FulfillmentStatus::Failed->value;
        }
        if ($statuses->contains(FulfillmentStatus::Processing->value)) {
            return FulfillmentStatus::Processing->value;
        }
        if ($statuses->contains(FulfillmentStatus::Queued->value)) {
            return FulfillmentStatus::Queued->value;
        }
        if ($statuses->contains(FulfillmentStatus::Completed->value)) {
            return FulfillmentStatus::Completed->value;
        }

        return FulfillmentStatus::Queued->value;
    }

    private function productSummary(Commission $commission): string
    {
        $items = $commission->order?->items;
        if ($items === null || $items->isEmpty()) {
            return '—';
        }

        return $items->pluck('name')->filter()->implode(', ');
    }
}
