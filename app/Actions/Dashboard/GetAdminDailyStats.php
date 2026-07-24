<?php

declare(strict_types=1);

namespace App\Actions\Dashboard;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\TopupRequestStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TopupRequest;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class GetAdminDailyStats
{
    public const CACHE_TTL_SECONDS = 60;

    public const FULFILLMENT_SLA_MINUTES = 30;

    /**
     * @return array{
     *     range: string,
     *     kpis: list<array{key: string, label: string, value: float|int, formatted: string, hint: string}>,
     *     chart: array{labels: list<string>, orders: list<int>, revenue: list<float>}
     * }
     */
    public function handle(string $range = '7d'): array
    {
        $range = in_array($range, ['today', '7d', 'this_month'], true) ? $range : '7d';

        return Cache::remember(
            $this->cacheKey($range),
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => $this->build($range),
        );
    }

    public function forgetCache(): void
    {
        foreach (['today', '7d', 'this_month'] as $range) {
            Cache::forget($this->cacheKey($range));
        }
    }

    private function cacheKey(string $range): string
    {
        return 'admin_daily_stats_v1_'.$range;
    }

    /**
     * @return array{
     *     range: string,
     *     kpis: list<array{key: string, label: string, value: float|int, formatted: string, hint: string}>,
     *     chart: array{labels: list<string>, orders: list<int>, revenue: list<float>}
     * }
     */
    private function build(string $range): array
    {
        [$start, $end] = $this->resolveRange($range);
        $paidStatuses = [
            OrderStatus::Paid,
            OrderStatus::Processing,
            OrderStatus::Fulfilled,
            OrderStatus::Failed,
            OrderStatus::Refunded,
        ];

        $ordersQuery = Order::query()
            ->whereBetween('created_at', [$start, $end])
            ->whereIn('status', $paidStatuses);

        $ordersCount = (int) (clone $ordersQuery)->count();
        $revenue = (float) (clone $ordersQuery)->sum('total');

        $completedFulfillments = Fulfillment::query()
            ->where('status', FulfillmentStatus::Completed)
            ->whereBetween('completed_at', [$start, $end])
            ->count();
        $failedFulfillments = Fulfillment::query()
            ->where('status', FulfillmentStatus::Failed)
            ->whereBetween('updated_at', [$start, $end])
            ->count();
        $terminalFulfillments = $completedFulfillments + $failedFulfillments;
        $successRate = $terminalFulfillments > 0
            ? round(($completedFulfillments / $terminalFulfillments) * 100, 1)
            : 100.0;

        $approvedTopups = (float) TopupRequest::query()
            ->where('status', TopupRequestStatus::Approved)
            ->whereBetween('approved_at', [$start, $end])
            ->sum('amount');

        $marginRow = OrderItem::query()
            ->whereNotNull('entry_price')
            ->whereHas('order', fn ($query) => $query
                ->whereBetween('created_at', [$start, $end])
                ->whereIn('status', $paidStatuses))
            ->selectRaw('COALESCE(SUM(line_total - (entry_price * quantity)), 0) as gross_margin')
            ->selectRaw('COALESCE(SUM(line_total), 0) as item_revenue')
            ->first();

        $grossMargin = (float) ($marginRow->gross_margin ?? 0);
        $itemRevenue = (float) ($marginRow->item_revenue ?? 0);
        $marginPercent = $itemRevenue > 0
            ? round(($grossMargin / $itemRevenue) * 100, 1)
            : 0.0;

        [$slaPercent, $avgFulfillmentMinutes] = $this->fulfillmentSlaMetrics($start, $end);

        $currencySymbol = config('billing.currency_symbol', '$');

        return [
            'range' => $range,
            'kpis' => [
                [
                    'key' => 'orders',
                    'label' => __('messages.admin_stats_orders'),
                    'value' => $ordersCount,
                    'formatted' => (string) $ordersCount,
                    'hint' => __('messages.admin_stats_orders_hint'),
                ],
                [
                    'key' => 'revenue',
                    'label' => __('messages.admin_stats_revenue'),
                    'value' => $revenue,
                    'formatted' => $currencySymbol.number_format($revenue, 2),
                    'hint' => __('messages.admin_stats_revenue_hint'),
                ],
                [
                    'key' => 'success_rate',
                    'label' => __('messages.admin_stats_success_rate'),
                    'value' => $successRate,
                    'formatted' => number_format($successRate, 1).'%',
                    'hint' => __('messages.admin_stats_success_rate_hint'),
                ],
                [
                    'key' => 'topups',
                    'label' => __('messages.admin_stats_topups'),
                    'value' => $approvedTopups,
                    'formatted' => $currencySymbol.number_format($approvedTopups, 2),
                    'hint' => __('messages.admin_stats_topups_hint'),
                ],
                [
                    'key' => 'gross_margin',
                    'label' => __('messages.admin_stats_gross_margin'),
                    'value' => $marginPercent,
                    'formatted' => number_format($marginPercent, 1).'%',
                    'hint' => __('messages.admin_stats_gross_margin_hint', [
                        'amount' => $currencySymbol.number_format($grossMargin, 2),
                    ]),
                ],
                [
                    'key' => 'fulfillment_sla',
                    'label' => __('messages.admin_stats_fulfillment_sla'),
                    'value' => $slaPercent,
                    'formatted' => number_format($slaPercent, 1).'%',
                    'hint' => __('messages.admin_stats_fulfillment_sla_hint', [
                        'minutes' => self::FULFILLMENT_SLA_MINUTES,
                        'avg' => $avgFulfillmentMinutes,
                    ]),
                ],
            ],
            'chart' => $this->buildChartSeries($start, $end, $paidStatuses),
        ];
    }

    /**
     * @return array{0: float, 1: int}
     */
    private function fulfillmentSlaMetrics(CarbonInterface $start, CarbonInterface $end): array
    {
        $completed = Fulfillment::query()
            ->where('status', FulfillmentStatus::Completed)
            ->whereBetween('completed_at', [$start, $end])
            ->whereNotNull('completed_at')
            ->get(['created_at', 'completed_at']);

        if ($completed->isEmpty()) {
            return [100.0, 0];
        }

        $slaHits = 0;
        $totalMinutes = 0;

        foreach ($completed as $fulfillment) {
            $minutes = (int) $fulfillment->created_at->diffInMinutes($fulfillment->completed_at);
            $totalMinutes += $minutes;

            if ($minutes <= self::FULFILLMENT_SLA_MINUTES) {
                $slaHits++;
            }
        }

        $slaPercent = round(($slaHits / $completed->count()) * 100, 1);
        $avgMinutes = (int) round($totalMinutes / $completed->count());

        return [$slaPercent, $avgMinutes];
    }

    /**
     * @param  list<OrderStatus>  $paidStatuses
     * @return array{labels: list<string>, orders: list<int>, revenue: list<float>}
     */
    private function buildChartSeries(CarbonInterface $start, CarbonInterface $end, array $paidStatuses): array
    {
        $start = Carbon::instance($start);
        $end = Carbon::instance($end);
        $days = min(14, max(1, $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1));
        $chartStart = $end->copy()->startOfDay()->subDays($days - 1);

        $ordersByDay = Order::query()
            ->selectRaw('DATE(created_at) as day, COUNT(*) as orders_count, COALESCE(SUM(total), 0) as revenue_total')
            ->whereBetween('created_at', [$chartStart, $end])
            ->whereIn('status', $paidStatuses)
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->keyBy(fn ($row) => (string) $row->day);

        $labels = [];
        $orders = [];
        $revenue = [];

        for ($i = 0; $i < $days; $i++) {
            $day = $chartStart->copy()->addDays($i);
            $key = $day->toDateString();
            $row = $ordersByDay->get($key);

            $labels[] = $day->format('M j');
            $orders[] = (int) ($row->orders_count ?? 0);
            $revenue[] = round((float) ($row->revenue_total ?? 0), 2);
        }

        return [
            'labels' => $labels,
            'orders' => $orders,
            'revenue' => $revenue,
        ];
    }

    /**
     * @return array{0: CarbonInterface, 1: CarbonInterface}
     */
    private function resolveRange(string $range): array
    {
        $end = now();

        return match ($range) {
            'today' => [$end->copy()->startOfDay(), $end],
            'this_month' => [$end->copy()->startOfMonth(), $end],
            default => [$end->copy()->subDays(6)->startOfDay(), $end],
        };
    }
}
