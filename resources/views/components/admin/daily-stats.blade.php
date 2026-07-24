@props([
    'stats',
    'activeRange' => '7d',
])

@php
    $chart = $stats['chart'] ?? ['labels' => [], 'orders' => [], 'revenue' => []];
    $maxOrders = max(1, ...($chart['orders'] ?: [1]));
    $maxRevenue = max(1.0, ...($chart['revenue'] ?: [1.0]));
    $barCount = count($chart['labels'] ?? []);
    $chartNeedsScroll = $barCount > 14;
    $ranges = [
        'today' => __('messages.admin_stats_range_today'),
        '7d' => __('messages.admin_stats_range_7d'),
        'this_month' => __('messages.admin_stats_range_month'),
    ];
@endphp

<section
    class="cf-panel cf-reveal cf-reveal-delay-2 min-w-0 max-w-full overflow-x-hidden"
    data-test="admin-daily-stats"
>
    <div class="flex flex-wrap items-start justify-between gap-4 border-b border-[var(--cf-border)] pb-5">
        <div class="space-y-1.5">
            <p class="cf-display text-[11px] font-semibold tracking-[0.2em] text-[var(--cf-primary)] uppercase">
                {{ __('messages.admin_stats_title') }}
            </p>
            <flux:heading size="md" class="cf-display tracking-tight text-[var(--cf-foreground)]">
                {{ __('messages.admin_stats_subtitle') }}
            </flux:heading>
        </div>

        <div class="cf-range-pill inline-flex flex-wrap gap-1">
            @foreach ($ranges as $range => $label)
                <button
                    type="button"
                    wire:click="setStatsRange('{{ $range }}')"
                    wire:key="stats-range-{{ $range }}"
                    data-test="stats-range-{{ $range }}"
                    @class([
                        'rounded-full px-3.5 py-1.5 text-xs font-semibold transition-colors duration-200',
                        'bg-[var(--cf-primary)] text-[var(--cf-primary-foreground)] shadow-sm' => $activeRange === $range,
                        'text-[var(--cf-muted-foreground)] hover:bg-[var(--cf-card)] hover:text-[var(--cf-foreground)]' => $activeRange !== $range,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </div>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($stats['kpis'] ?? [] as $kpi)
            <x-admin.kpi-card :kpi="$kpi" />
        @endforeach
    </div>

    @if (($chart['labels'] ?? []) !== [])
        <div class="mt-8 grid min-w-0 gap-6 lg:grid-cols-2" data-test="admin-stats-chart">
            <div class="min-w-0">
                <div class="mb-3 flex min-w-0 flex-wrap items-center justify-between gap-2">
                    <flux:heading size="sm" class="min-w-0 text-[var(--cf-foreground)]">
                        {{ __('messages.admin_stats_chart_orders') }}
                    </flux:heading>
                    <flux:badge color="zinc" size="sm" class="shrink-0">{{ __('messages.admin_stats_chart_daily') }}</flux:badge>
                </div>
                <div class="cf-chart-panel">
                    <div @class(['cf-chart-scroll', 'cf-chart-scroll--wide' => $chartNeedsScroll]) dir="ltr">
                        @foreach ($chart['labels'] as $index => $label)
                            @php
                                $orderCount = (int) ($chart['orders'][$index] ?? 0);
                                $orderHeight = max(12, (int) round(($orderCount / $maxOrders) * 112));
                            @endphp
                            <div class="cf-chart-column" wire:key="chart-order-{{ $index }}">
                                <span class="text-[10px] font-semibold tabular-nums text-[var(--cf-muted-foreground)] sm:text-[11px]">{{ $orderCount }}</span>
                                <div
                                    class="cf-chart-bar cf-chart-bar--orders"
                                    style="height: {{ $orderHeight }}px"
                                ></div>
                                <span class="max-w-full truncate text-[9px] font-medium text-[var(--cf-muted-foreground)] sm:text-[10px]">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>

            <div class="min-w-0">
                <div class="mb-3 flex min-w-0 flex-wrap items-center justify-between gap-2">
                    <flux:heading size="sm" class="min-w-0 text-[var(--cf-foreground)]">
                        {{ __('messages.admin_stats_chart_revenue') }}
                    </flux:heading>
                    <flux:badge color="zinc" size="sm" class="shrink-0">{{ __('messages.admin_stats_chart_daily') }}</flux:badge>
                </div>
                <div class="cf-chart-panel">
                    <div @class(['cf-chart-scroll', 'cf-chart-scroll--wide' => $chartNeedsScroll]) dir="ltr">
                        @foreach ($chart['labels'] as $index => $label)
                            @php
                                $revenue = (float) ($chart['revenue'][$index] ?? 0);
                                $revenueHeight = max(12, (int) round(($revenue / $maxRevenue) * 112));
                            @endphp
                            <div class="cf-chart-column" wire:key="chart-revenue-{{ $index }}">
                                <span class="max-w-full truncate text-[10px] font-semibold tabular-nums text-[var(--cf-muted-foreground)] sm:text-[11px]">{{ config('billing.currency_symbol', '$') }}{{ number_format($revenue, 0) }}</span>
                                <div
                                    class="cf-chart-bar cf-chart-bar--revenue"
                                    style="height: {{ $revenueHeight }}px"
                                ></div>
                                <span class="max-w-full truncate text-[9px] font-medium text-[var(--cf-muted-foreground)] sm:text-[10px]">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            </div>
        </div>
    @endif
</section>
