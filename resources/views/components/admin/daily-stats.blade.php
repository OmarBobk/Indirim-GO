@props([
    'stats',
    'activeRange' => '7d',
])

@php
    $chart = $stats['chart'] ?? ['labels' => [], 'orders' => [], 'revenue' => []];
    $maxOrders = max(1, ...($chart['orders'] ?: [1]));
    $maxRevenue = max(1.0, ...($chart['revenue'] ?: [1.0]));
@endphp

<section
    class="cf-reveal rounded-2xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-5 shadow-sm"
    data-test="admin-daily-stats"
>
    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="space-y-1">
            <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                {{ __('messages.admin_stats_title') }}
            </flux:heading>
            <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                {{ __('messages.admin_stats_subtitle') }}
            </flux:text>
        </div>

        <div class="flex flex-wrap gap-2">
            @foreach (['today' => __('messages.admin_stats_range_today'), '7d' => __('messages.admin_stats_range_7d'), 'this_month' => __('messages.admin_stats_range_month')] as $range => $label)
                <flux:button
                    size="sm"
                    :variant="$activeRange === $range ? 'primary' : 'ghost'"
                    wire:click="setStatsRange('{{ $range }}')"
                    wire:key="stats-range-{{ $range }}"
                    data-test="stats-range-{{ $range }}"
                >
                    {{ $label }}
                </flux:button>
            @endforeach
        </div>
    </div>

    <div class="mt-5 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($stats['kpis'] ?? [] as $kpi)
            <div
                wire:key="admin-stat-{{ $kpi['key'] }}"
                class="rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card-elevated)] p-4"
                data-test="admin-stat-{{ $kpi['key'] }}"
            >
                <p class="text-xs font-semibold uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                    {{ $kpi['label'] }}
                </p>
                <p class="mt-2 text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]">
                    {{ $kpi['formatted'] }}
                </p>
                <p class="mt-1 text-xs text-[var(--cf-muted-foreground)]">{{ $kpi['hint'] }}</p>
            </div>
        @endforeach
    </div>

    @if (($chart['labels'] ?? []) !== [])
        <div class="mt-6 space-y-4" data-test="admin-stats-chart">
            <flux:heading size="sm" class="text-[var(--cf-foreground)]">
                {{ __('messages.admin_stats_chart_orders') }}
            </flux:heading>
            <div class="flex items-end gap-2 overflow-x-auto pb-1" dir="ltr">
                @foreach ($chart['labels'] as $index => $label)
                    @php
                        $orderCount = (int) ($chart['orders'][$index] ?? 0);
                        $orderHeight = max(8, (int) round(($orderCount / $maxOrders) * 96));
                    @endphp
                    <div class="flex min-w-10 flex-1 flex-col items-center gap-2" wire:key="chart-order-{{ $index }}">
                        <span class="text-[11px] font-medium tabular-nums text-[var(--cf-muted-foreground)]">{{ $orderCount }}</span>
                        <div
                            class="w-full max-w-12 rounded-t-md bg-[var(--cf-primary)]/80"
                            style="height: {{ $orderHeight }}px"
                        ></div>
                        <span class="text-[10px] text-[var(--cf-muted-foreground)]">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <flux:heading size="sm" class="text-[var(--cf-foreground)]">
                {{ __('messages.admin_stats_chart_revenue') }}
            </flux:heading>
            <div class="flex items-end gap-2 overflow-x-auto pb-1" dir="ltr">
                @foreach ($chart['labels'] as $index => $label)
                    @php
                        $revenue = (float) ($chart['revenue'][$index] ?? 0);
                        $revenueHeight = max(8, (int) round(($revenue / $maxRevenue) * 96));
                    @endphp
                    <div class="flex min-w-10 flex-1 flex-col items-center gap-2" wire:key="chart-revenue-{{ $index }}">
                        <span class="text-[11px] font-medium tabular-nums text-[var(--cf-muted-foreground)]">{{ config('billing.currency_symbol', '$') }}{{ number_format($revenue, 0) }}</span>
                        <div
                            class="w-full max-w-12 rounded-t-md bg-emerald-500/70"
                            style="height: {{ $revenueHeight }}px"
                        ></div>
                        <span class="text-[10px] text-[var(--cf-muted-foreground)]">{{ $label }}</span>
                    </div>
                @endforeach
            </div>
        </div>
    @endif
</section>
