@props([
    'kpis' => [],
    'chartSeries' => [],
    'chartPresets' => [],
    'activeRange' => '30d',
    'chartFilter' => 'this_month',
    'chartStartDate' => null,
    'chartEndDate' => null,
    'heroKey' => 'hero-chart-default',
])

<section
    wire:key="{{ $heroKey }}"
    class="glass-card overflow-hidden rounded-2xl p-0"
    x-data="dashboardHeroChart({
        chartSeries: @js($chartSeries),
        chartPresets: @js($chartPresets),
        activeRange: @js($activeRange),
        initialChartFilter: @js($chartFilter),
    })"
    x-init="init()"
>
    <div class="dashboard-earnings-panel dashboard-earnings-panel--hero space-y-5 p-4 sm:p-5">
        <div class="dashboard-kpi-grid">
        @foreach ($kpis as $metric)
            @php
                $accentVar = match ($metric['key']) {
                    'orders' => '--accent-orders',
                    'pending' => '--accent-pending',
                    default => '--accent-earnings',
                };
                $fluxIcon = match ($metric['key']) {
                    'paid' => 'banknotes',
                    'orders' => 'shopping-bag',
                    'pending' => 'clock',
                    default => 'wallet',
                };
                $deltaPositive = (float) $metric['delta'] >= 0;
                $deltaTrendIcon = $deltaPositive ? 'chevron-up' : 'chevron-down';
                $sparkId = 'kpi-spark-'.$metric['key'];
            @endphp
            <article
                class="dashboard-kpi-tile animate-dashboard-kpi"
                style="animation-delay: {{ $loop->index * 65 }}ms"
            >
                <span
                    class="dashboard-kpi-tile__accent-line"
                    style="background: linear-gradient(90deg, transparent, hsl(var({{ $accentVar }}) / 0.85), transparent);"
                ></span>
                <div class="relative flex items-start justify-between gap-2">
                    <div class="flex min-w-0 flex-1 items-start gap-2 sm:items-center">
                        <span
                            class="grid size-8 shrink-0 place-items-center rounded-lg ring-1 ring-white/10"
                            style="background: hsl(var({{ $accentVar }}) / 0.14); color: hsl(var({{ $accentVar }}));"
                        >
                            <flux:icon :icon="$fluxIcon" variant="outline" class="size-4" />
                        </span>
                        <p class="dashboard-earnings-eyebrow min-w-0 flex-1 text-pretty break-words leading-snug sm:leading-tight">
                            {{ $metric['label'] }}
                        </p>
                    </div>
                    <span
                        @class([
                            'inline-flex shrink-0 items-center gap-0.5 rounded-md px-1.5 py-0.5 text-[11px] font-semibold ring-1',
                            'bg-[hsl(var(--accent-earnings)/0.14)] text-[hsl(var(--accent-earnings))] ring-[hsl(var(--accent-earnings)/0.28)]' => $deltaPositive,
                            'bg-[hsl(var(--accent-failed)/0.14)] text-[hsl(var(--accent-failed))] ring-[hsl(var(--accent-failed)/0.28)]' => ! $deltaPositive,
                        ])
                    >
                        <flux:icon :icon="$deltaTrendIcon" variant="outline" class="size-3" />
                        @if ($deltaPositive)+@endif{{ number_format((float) $metric['delta'], 1) }}%
                    </span>
                </div>
                <div class="relative mt-3 flex flex-col gap-2.5 min-[420px]:flex-row min-[420px]:items-end min-[420px]:justify-between min-[420px]:gap-3">
                    <div class="min-w-0 w-full min-[420px]:flex-1">
                        <p
                            class="num text-2xl font-semibold leading-none tracking-tight sm:text-[1.65rem]"
                            style="color: hsl(var({{ $accentVar }}));"
                            x-text="kpiDisplayText('{{ $metric['key'] }}')"
                        >
                            @if ($metric['key'] === 'orders')
                                {{ (int) $metric['value'] }}
                            @else
                                ${{ number_format((float) $metric['value'], 2) }}
                            @endif
                        </p>
                        <p class="dashboard-kpi-hint mt-1.5 max-w-prose text-pretty">
                            {{ $metric['hint'] ?? '' }}
                        </p>
                    </div>
                    <svg
                        xmlns="http://www.w3.org/2000/svg"
                        width="88"
                        height="30"
                        viewBox="0 0 88 30"
                        class="h-[30px] w-[5.5rem] shrink-0 self-end overflow-visible"
                        style="color: hsl(var({{ $accentVar }}));"
                        aria-hidden="true"
                        dir="ltr"
                    >
                        <defs>
                            <linearGradient id="{{ $sparkId }}" x1="0" y1="0" x2="0" y2="1">
                                <stop offset="0%" stop-color="currentColor" stop-opacity="0.42" />
                                <stop offset="100%" stop-color="currentColor" stop-opacity="0" />
                            </linearGradient>
                        </defs>
                        <polygon
                            fill="url(#{{ $sparkId }})"
                            :points="kpiSparkGeometry('{{ $metric['key'] }}').polygon"
                        />
                        <polyline
                            fill="none"
                            stroke="currentColor"
                            stroke-width="1.75"
                            stroke-linejoin="round"
                            stroke-linecap="round"
                            :points="kpiSparkGeometry('{{ $metric['key'] }}').polyline"
                        />
                    </svg>
                </div>
            </article>
        @endforeach
        </div>

        <div class="mb-4 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0 space-y-1.5">
                <p class="dashboard-earnings-eyebrow">{{ __('messages.earnings_performance') }}</p>
                <p class="truncate text-lg font-semibold tracking-tight text-white sm:text-xl">
                    <span class="tabular-nums" x-text="rangeLabel()"></span>
                </p>
            </div>
            <div class="flex w-full flex-col items-stretch gap-2.5 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
                <div
                    class="dashboard-segment-track dashboard-segment-track--series shrink-0"
                    role="group"
                    aria-label="{{ __('messages.chart_metric') }}"
                >
                    <button
                        type="button"
                        class="dashboard-segment-btn dashboard-segment-btn--series"
                        :class="selectedSeries === 'earnings' ? 'dashboard-segment-btn--series-earnings-active' : ''"
                        x-on:click="toggleSeries('earnings')"
                    >
                        <span class="dashboard-series-dot dashboard-series-dot--earnings" aria-hidden="true"></span>
                        {{ __('messages.metric_earnings') }}
                    </button>
                    <button
                        type="button"
                        class="dashboard-segment-btn dashboard-segment-btn--series"
                        :class="selectedSeries === 'orders' ? 'dashboard-segment-btn--series-orders-active' : ''"
                        x-on:click="toggleSeries('orders')"
                    >
                        <span class="dashboard-series-dot dashboard-series-dot--orders" aria-hidden="true"></span>
                        {{ __('messages.orders') }}
                    </button>
                </div>

                <div
                    class="dashboard-segment-track dashboard-segment-track--time min-w-0 flex-1 sm:flex-initial"
                    role="group"
                    aria-label="{{ __('messages.chart_time_range') }}"
                    wire:loading.class="pointer-events-none opacity-60"
                    wire:target="setChartFilter,chartStartDate,chartEndDate"
                >
                    <button
                        type="button"
                        x-on:click.prevent="applyPreset('today')"
                        :class="{
                            'dashboard-segment-btn cursor-pointer': true,
                            'dashboard-segment-btn--active-time': localChartFilter === 'today',
                        }"
                    >{{ __('messages.today') }}</button>
                    <button
                        type="button"
                        x-on:click.prevent="applyPreset('7d')"
                        :class="{
                            'dashboard-segment-btn cursor-pointer': true,
                            'dashboard-segment-btn--active-time': localChartFilter === '7d',
                        }"
                    >7d</button>
                    <button
                        type="button"
                        x-on:click.prevent="applyPreset('this_month')"
                        :class="{
                            'dashboard-segment-btn cursor-pointer': true,
                            'dashboard-segment-btn--active-time': localChartFilter === 'this_month',
                        }"
                    >{{ __('messages.this_month') }}</button>
                    <button
                        type="button"
                        x-on:click.prevent="applyPreset('ytd')"
                        :class="{
                            'dashboard-segment-btn cursor-pointer': true,
                            'dashboard-segment-btn--active-time': localChartFilter === 'ytd',
                        }"
                    >YTD</button>
                    <button
                        type="button"
                        wire:click="setChartFilter('custom')"
                        wire:loading.attr="disabled"
                        wire:target="setChartFilter,chartStartDate,chartEndDate"
                        @class([
                            'dashboard-segment-btn cursor-pointer',
                            'dashboard-segment-btn--active-time' => $chartFilter === 'custom',
                        ])
                    >{{ __('messages.custom') }}</button>
                </div>
            </div>
        </div>

        @if ($chartFilter === 'custom')
            <div class="mb-4 grid gap-3 sm:grid-cols-2">
                <flux:field>
                    <flux:label>{{ __('messages.chart_range_start') }}</flux:label>
                    <flux:input type="date" wire:model.live.debounce.400ms="chartStartDate" />
                </flux:field>
                <flux:field>
                    <flux:label>{{ __('messages.chart_range_end') }}</flux:label>
                    <flux:input type="date" wire:model.live.debounce.400ms="chartEndDate" />
                </flux:field>
            </div>
        @endif

        <div class="relative min-h-[13rem] w-full">
            <div
                wire:loading.delay.shortest
                wire:loading.class.remove="hidden"
                wire:loading.class="flex"
                wire:target="setChartFilter,chartStartDate,chartEndDate"
                class="absolute inset-0 z-10 hidden items-center justify-center rounded-lg bg-[hsl(var(--surface-1)/0.72)] backdrop-blur-[2px]"
            >
                <span class="text-xs font-medium text-zinc-400">{{ __('messages.chart_updating') }}</span>
            </div>
            <div class="h-52 w-full" wire:ignore>
                <div x-ref="heroChart" class="h-52 w-full"></div>
            </div>
        </div>
    </div>
</section>
