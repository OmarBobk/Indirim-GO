@props(['orders' => []])

@php
    $statusTabs = [
        ['value' => 'all', 'label' => __('messages.dashboard_orders_filter_all')],
        ['value' => 'pending', 'label' => __('messages.pending')],
        ['value' => 'credited', 'label' => __('messages.commission_status_credited')],
        ['value' => 'failed', 'label' => __('messages.commission_status_failed')],
    ];
    $ordersTableStrings = [
        'today' => __('messages.today'),
        'empty' => __('messages.dashboard_orders_empty'),
        'commission_status' => [
            'pending' => __('messages.commission_status_pending'),
            'credited' => __('messages.commission_status_credited'),
            'failed' => __('messages.commission_status_failed'),
        ],
        'fulfillment_status' => [
            'queued' => __('messages.fulfillment_status_queued'),
            'processing' => __('messages.fulfillment_status_processing'),
            'completed' => __('messages.fulfillment_status_completed'),
            'failed' => __('messages.fulfillment_status_failed'),
            'cancelled' => __('messages.fulfillment_status_cancelled'),
        ],
    ];
@endphp

<section
    class="glass-card overflow-hidden rounded-2xl p-5 sm:p-6 md:p-7"
    x-data="salespersonOrdersTable({ orders: @js($orders), strings: @js($ordersTableStrings), serverToday: @js(\Illuminate\Support\Carbon::today()->toDateString()) })"
>
    <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
        <div class="flex min-w-0 items-start gap-3">
            <span
                class="grid size-9 shrink-0 place-items-center rounded-xl ring-1 ring-[hsl(var(--accent-orders)/0.22)]"
                style="background: hsl(var(--accent-orders) / 0.14);"
            >
                <flux:icon icon="queue-list" variant="outline" class="size-4 text-[hsl(var(--accent-orders))]" />
            </span>
            <div class="min-w-0">
                <h2 class="text-base font-semibold tracking-tight text-white">
                    {{ __('messages.dashboard_orders_title') }}
                </h2>
                <p class="mt-0.5 text-xs leading-snug text-[hsl(var(--foreground)/0.48)]">
                    {{ __('messages.dashboard_orders_subtitle') }}
                </p>
            </div>
        </div>

        <div class="flex w-full flex-col gap-2.5 sm:w-auto sm:flex-row sm:flex-wrap sm:items-center sm:justify-end">
            <label class="relative flex min-w-0 flex-1 items-center gap-2 rounded-xl border border-white/[0.08] bg-[hsl(var(--surface-2)/0.55)] px-3 py-2 ring-1 ring-white/[0.04] sm:max-w-xs sm:flex-initial md:min-w-[14rem]">
                <flux:icon icon="magnifying-glass" variant="outline" class="size-3.5 shrink-0 text-[hsl(var(--foreground)/0.45)]" />
                <input
                    type="search"
                    x-model.debounce.250ms="search"
                    placeholder="{{ __('messages.dashboard_orders_search_placeholder') }}"
                    class="min-w-0 flex-1 border-0 bg-transparent text-xs text-zinc-100 outline-none placeholder:text-[hsl(var(--foreground)/0.38)]"
                    autocomplete="off"
                />
            </label>

            <div
                class="dashboard-segment-track dashboard-segment-track--orders shrink-0"
                role="tablist"
                aria-label="{{ __('messages.dashboard_orders_col_status') }}"
            >
                @foreach ($statusTabs as $tab)
                    <button
                        type="button"
                        @click="statusFilter = '{{ $tab['value'] }}'"
                        @class([
                            'dashboard-segment-btn dashboard-segment-btn--orders-tab cursor-pointer px-2.5 py-1 text-[11px] font-semibold sm:px-3 sm:text-xs',
                        ])
                        :class="statusFilter === '{{ $tab['value'] }}' ? 'dashboard-segment-btn--orders-tab-active' : ''"
                    >
                        {{ $tab['label'] }}
                    </button>
                @endforeach
            </div>

            <div class="flex shrink-0 items-center gap-2">
                <span class="hidden text-[10px] font-semibold uppercase tracking-wider text-[hsl(var(--foreground)/0.4)] sm:inline">
                    {{ __('messages.dashboard_orders_date_range') }}
                </span>
                <select
                    x-model="dateRange"
                    class="h-9 min-w-[5.5rem] cursor-pointer rounded-xl border border-white/[0.08] bg-[hsl(var(--surface-2)/0.55)] px-2.5 text-xs font-medium text-zinc-100 ring-1 ring-white/[0.04] outline-none transition hover:border-white/15 focus:border-[hsl(var(--accent-earnings)/0.45)] focus:ring-[hsl(var(--accent-earnings)/0.25)]"
                >
                    <option value="7d">7d</option>
                    <option value="30d">30d</option>
                    <option value="ytd">YTD</option>
                </select>
            </div>
        </div>
    </div>

    <div class="mt-5 overflow-x-auto rounded-xl border border-white/[0.06] bg-[hsl(var(--surface-2)/0.4)] ring-1 ring-white/[0.04]">
        <div class="min-w-[52rem]">
            <div
                class="sticky top-0 z-10 grid grid-cols-[minmax(9rem,1.15fr)_minmax(10rem,1.35fr)_minmax(5.5rem,0.75fr)_minmax(5.5rem,0.75fr)_minmax(6.5rem,0.85fr)] gap-3 border-b border-white/[0.08] bg-[hsl(var(--surface-2)/0.92)] px-4 py-2.5 backdrop-blur-sm"
            >
                <div class="dashboard-earnings-eyebrow">{{ __('messages.dashboard_orders_col_order') }}</div>
                <div class="dashboard-earnings-eyebrow">{{ __('messages.dashboard_orders_col_customer') }}</div>
                <div class="dashboard-earnings-eyebrow text-end">{{ __('messages.dashboard_orders_col_sale') }}</div>
                <div class="dashboard-earnings-eyebrow text-end">{{ __('messages.dashboard_orders_col_commission') }}</div>
                <div class="dashboard-earnings-eyebrow text-end">{{ __('messages.dashboard_orders_col_status') }}</div>
            </div>

            <template x-if="groupedOrders().length === 0">
                <div class="px-4 py-12 text-center text-sm text-[hsl(var(--foreground)/0.5)]" x-text="strings.empty"></div>
            </template>

            <template x-for="group in groupedOrders()" :key="group.key">
                <div>
                    <div class="border-b border-white/[0.05] bg-[hsl(var(--surface-3)/0.35)] px-4 py-2 text-[11px] font-semibold uppercase tracking-wider text-[hsl(var(--foreground)/0.48)]">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <span x-text="group.label"></span>
                            <span class="num font-medium normal-case tracking-normal text-[hsl(var(--foreground)/0.55)]">
                                <span x-text="group.count"></span> {{ __('messages.orders') }} · <span x-text="money(group.commission)"></span>
                            </span>
                        </div>
                    </div>
                    <template x-for="(row, idx) in group.rows" :key="row.order + '|' + row.date + '|' + idx">
                        <div
                            class="grid grid-cols-[minmax(9rem,1.15fr)_minmax(10rem,1.35fr)_minmax(5.5rem,0.75fr)_minmax(5.5rem,0.75fr)_minmax(6.5rem,0.85fr)] gap-3 border-b border-white/[0.05] px-4 py-3 transition-colors last:border-b-0 hover:bg-[hsl(var(--surface-3)/0.28)]"
                        >
                            <div class="min-w-0">
                                <p class="num font-mono text-xs font-medium text-white" x-text="row.order"></p>
                                <p class="mt-0.5 text-[10px] text-[hsl(var(--foreground)/0.42)] tabular-nums" x-text="row.date"></p>
                                <p class="mt-1 line-clamp-2 text-[11px] leading-snug text-[hsl(var(--foreground)/0.45)]" x-text="row.product"></p>
                            </div>
                            <div class="min-w-0 self-center" dir="auto">
                                <p class="line-clamp-2 text-sm leading-snug text-zinc-200">
                                    <span x-text="row.customer_name"></span>
                                    <template x-if="String(row.customer_phone ?? '').trim().length">
                                        <span>
                                            <span class="text-[hsl(var(--foreground)/0.38)]"> | </span>
                                            <span dir="ltr" class="inline-block [unicode-bidi:isolate] tabular-nums" x-text="row.customer_phone"></span>
                                        </span>
                                    </template>
                                    <template x-if="String(row.customer_username ?? '').trim().length">
                                        <span>
                                            <span class="text-[hsl(var(--foreground)/0.38)]"> | </span>
                                            <span dir="ltr" class="inline-block [unicode-bidi:isolate]" x-text="'@' + row.customer_username"></span>
                                        </span>
                                    </template>
                                </p>
                            </div>
                            <div class="num self-center text-end text-sm text-zinc-200 tabular-nums" x-text="money(row.sale)"></div>
                            <div
                                class="num self-center text-end text-sm font-semibold tabular-nums text-[hsl(var(--accent-earnings))]"
                                x-text="money(row.commission)"
                            ></div>
                            <div class="min-w-0 self-center text-end">
                                <span
                                    class="status-pill inline-flex max-w-full"
                                    :class="'status-pill--' + (row.commission_status || 'pending')"
                                    x-text="commissionLabel(row.commission_status)"
                                ></span>
                                <p class="mt-1 text-[10px] text-[hsl(var(--foreground)/0.42)]">
                                    {{ __('messages.dashboard_orders_fulfillment_prefix') }}:
                                    <span class="text-[hsl(var(--foreground)/0.55)]" x-text="fulfillmentLabel(row.fulfillment_status)"></span>
                                </p>
                            </div>
                        </div>
                    </template>
                </div>
            </template>
        </div>
    </div>
</section>
