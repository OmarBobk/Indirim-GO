@props([
    'inbox',
])

@php
    $variant = $inbox['variant'] ?? 'full';
    $actionableTotal = (int) ($inbox['actionable_exception_total'] ?? 0);
    $queueHealth = $inbox['queue_health'] ?? null;
    $load = $queueHealth['load'] ?? 'normal';
    $loadPercent = match ($load) {
        'high' => 88,
        'medium' => 58,
        default => 28,
    };
    $loadColor = match ($load) {
        'high' => 'red',
        'medium' => 'amber',
        default => 'green',
    };
    $loadMeterClass = match ($load) {
        'high' => 'cf-queue-meter__fill--high',
        'medium' => 'cf-queue-meter__fill--medium',
        default => 'cf-queue-meter__fill--normal',
    };
@endphp

<div
    class="flex h-full w-full min-w-0 max-w-full flex-1 flex-col gap-6 overflow-x-hidden lg:gap-8"
    data-test="admin-ops-dashboard"
    data-variant="{{ $variant }}"
>
    <section class="cf-ops-hero cf-reveal">
        <div class="relative z-10 flex flex-col gap-5">
            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="max-w-2xl space-y-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <p class="cf-display text-[11px] font-semibold tracking-[0.22em] text-[var(--cf-primary)] uppercase">
                            {{ __('messages.nav_overview') }}
                        </p>
                        @if ($actionableTotal > 0)
                            <flux:badge color="red" size="sm">
                                {{ number_format($actionableTotal) }} {{ __('messages.admin_ops_exceptions_open') }}
                            </flux:badge>
                        @endif
                    </div>
                    <flux:heading size="xl" class="cf-display tracking-tight text-[var(--cf-foreground)]">
                        {{ __('messages.dashboard') }}
                    </flux:heading>
                    <flux:text class="text-sm leading-relaxed text-[var(--cf-muted-foreground)]">
                        {{ $inbox['intro'] ?? __('messages.admin_ops_intro') }}
                    </flux:text>
                </div>

                @if ($queueHealth !== null)
                    <div class="w-full min-w-[12rem] max-w-xs rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card)]/80 p-4 backdrop-blur-sm sm:w-auto">
                        <div class="flex items-center justify-between gap-2">
                            <p class="text-xs font-semibold uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                {{ __('messages.admin_ops_queue_load') }}
                            </p>
                            <flux:badge color="{{ $loadColor }}" size="sm">
                                {{ __('messages.admin_ops_load_'.$load) }}
                            </flux:badge>
                        </div>
                        <div class="cf-queue-meter mt-3">
                            <div
                                class="cf-queue-meter__fill {{ $loadMeterClass }}"
                                style="width: {{ $loadPercent }}%"
                            ></div>
                        </div>
                    </div>
                @endif
            </div>

            @if ($inbox['all_clear'] ?? false)
                <div
                    class="rounded-xl border border-[var(--cf-success)]/30 bg-[var(--cf-success-soft)] px-4 py-3"
                    data-test="ops-all-clear"
                >
                    <div class="flex items-start gap-3">
                        <flux:icon name="check-circle" class="mt-0.5 size-5 shrink-0 text-[var(--cf-success)]" />
                        <flux:text class="text-sm text-[var(--cf-foreground)]">
                            {{ __('messages.admin_ops_all_clear_'.$variant) }}
                        </flux:text>
                    </div>
                </div>
            @endif

            @if ($variant === 'orders' && auth()->user()?->can('view_referrals'))
                <div>
                    <flux:button
                        size="sm"
                        variant="primary"
                        icon="chart-bar"
                        :href="route('salesperson.dashboard')"
                        wire:navigate
                        data-test="supervisor-sales-dashboard-cta"
                    >
                        {{ __('messages.admin_ops_salesperson_dashboard_cta') }}
                    </flux:button>
                </div>
            @endif

            @if (($inbox['exception_cards'] ?? []) !== [])
                <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    @foreach ($inbox['exception_cards'] as $index => $card)
                        <x-admin.ops-exception-card :card="$card" :index="$index" />
                    @endforeach
                </div>
            @endif
        </div>
    </section>

    @if ($queueHealth !== null)
        <section class="cf-panel cf-reveal cf-reveal-delay-1" data-test="queue-health-panel">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_queue_health') }}
                    </flux:heading>
                    <flux:text class="mt-1 text-sm text-[var(--cf-muted-foreground)]">
                        {{ __('messages.admin_ops_queue_health_hint') }}
                    </flux:text>
                </div>
                <flux:badge color="{{ $loadColor }}">
                    {{ __('messages.admin_ops_load_'.$load) }}
                </flux:badge>
            </div>

            <div class="mt-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                @foreach ([
                    ['label' => __('messages.fulfillment_status_queued'), 'value' => $queueHealth['queued']],
                    ['label' => __('messages.fulfillment_status_processing'), 'value' => $queueHealth['processing']],
                    ['label' => __('messages.fulfillment_status_completed'), 'value' => $queueHealth['completed']],
                    ['label' => __('messages.admin_ops_active_supervisors'), 'value' => $queueHealth['active_supervisors']],
                ] as $metric)
                    <div class="cf-stat-card">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-[var(--cf-muted-foreground)]">{{ $metric['label'] }}</p>
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]">{{ number_format($metric['value']) }}</p>
                    </div>
                @endforeach
                @if (($queueHealth['browser_needs_review'] ?? 0) > 0)
                    <div class="cf-stat-card border-amber-500/35 bg-amber-500/5">
                        <p class="text-[11px] font-semibold uppercase tracking-wide text-amber-400">{{ __('messages.admin_ops_automation_review') }}</p>
                        <p class="mt-2 text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]">{{ number_format($queueHealth['browser_needs_review']) }}</p>
                    </div>
                @endif
            </div>

            @if (($inbox['admin_alerts'] ?? []) !== [])
                <div class="mt-5 flex flex-col gap-2">
                    @foreach ($inbox['admin_alerts'] as $alert)
                        <flux:callout
                            wire:key="ops-alert-{{ $loop->index }}"
                            variant="subtle"
                            icon="exclamation-triangle"
                        >
                            <strong>{{ $alert['title'] }}</strong> — {{ $alert['message'] }}
                        </flux:callout>
                    @endforeach
                </div>
            @endif

            <div class="mt-5">
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="arrow-top-right-on-square"
                    :href="route('fulfillments')"
                    wire:navigate
                    data-test="queue-health-fulfillments-link"
                >
                    {{ __('messages.view_fulfillments') }}
                </flux:button>
            </div>
        </section>
    @endif

    <div class="grid gap-6 lg:grid-cols-2">
        @if ($variant === 'orders' && auth()->user()?->can('view_orders'))
            <section class="cf-panel cf-reveal cf-reveal-delay-2 lg:col-span-2" data-test="recent-orders">
                <div class="flex items-center justify-between gap-3 border-b border-[var(--cf-border)] pb-4">
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_recent_orders') }}
                    </flux:heading>
                    <flux:button size="sm" variant="ghost" :href="route('admin.orders.index')" wire:navigate>
                        {{ __('messages.view_all') }}
                    </flux:button>
                </div>

                @if (($inbox['recent_orders'] ?? []) === [])
                    <x-admin.ops-empty class="mt-4" icon="shopping-bag" :message="__('messages.admin_ops_no_recent_orders')" />
                @else
                    <div class="cf-table-shell cf-table-row-hover mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.order_details') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.user') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.status') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_orders'] as $order)
                                    <tr wire:key="recent-order-{{ $order['id'] }}">
                                        <td class="px-4 py-3.5">
                                            <a href="{{ $order['href'] }}" wire:navigate class="font-medium text-[var(--cf-primary)] hover:underline">
                                                {{ $order['order_number'] }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3.5 text-[var(--cf-muted-foreground)]">{{ $order['user_name'] }}</td>
                                        <td class="px-4 py-3.5">
                                            <flux:badge color="zinc">{{ __('messages.order_status_'.$order['status']) }}</flux:badge>
                                        </td>
                                        <td class="px-4 py-3.5 font-medium tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                            {{ config('billing.currency_symbol', '$') }}{{ number_format($order['total'], 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        @if (in_array($variant, ['full', 'finance'], true) && auth()->user()?->can('view_refunds'))
            <section class="cf-panel cf-reveal cf-reveal-delay-2" data-test="recent-pending-refunds">
                <div class="flex items-center justify-between gap-3 border-b border-[var(--cf-border)] pb-4">
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_recent_refunds') }}
                    </flux:heading>
                    <flux:button size="sm" variant="ghost" :href="route('refunds')" wire:navigate>
                        {{ __('messages.view_all') }}
                    </flux:button>
                </div>

                @if (($inbox['recent_pending_refunds'] ?? []) === [])
                    <x-admin.ops-empty class="mt-4" icon="receipt-refund" :message="__('messages.admin_ops_no_pending_refunds')" />
                @else
                    <div class="cf-table-shell cf-table-row-hover mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.order_details') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.user') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_pending_refunds'] as $refund)
                                    <tr wire:key="recent-refund-{{ $refund['id'] }}">
                                        <td class="px-4 py-3.5 font-medium text-[var(--cf-foreground)]">
                                            {{ $refund['order_number'] ?? '#'.$refund['id'] }}
                                        </td>
                                        <td class="px-4 py-3.5 text-[var(--cf-muted-foreground)]">{{ $refund['user_name'] }}</td>
                                        <td class="px-4 py-3.5 font-medium tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                            {{ config('billing.currency_symbol', '$') }}{{ number_format($refund['amount'], 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        @if ($variant === 'finance' && auth()->user()?->can('manage_topups'))
            <section class="cf-panel cf-reveal cf-reveal-delay-3" data-test="recent-pending-topups">
                <div class="flex items-center justify-between gap-3 border-b border-[var(--cf-border)] pb-4">
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_recent_topups') }}
                    </flux:heading>
                    <flux:button size="sm" variant="ghost" :href="route('topups')" wire:navigate>
                        {{ __('messages.view_all') }}
                    </flux:button>
                </div>

                @if (($inbox['recent_pending_topups'] ?? []) === [])
                    <x-admin.ops-empty class="mt-4" icon="wallet" :message="__('messages.admin_ops_no_pending_topups')" />
                @else
                    <div class="cf-table-shell cf-table-row-hover mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.user') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_pending_topups'] as $topup)
                                    <tr wire:key="recent-topup-{{ $topup['id'] }}">
                                        <td class="px-4 py-3.5 text-[var(--cf-muted-foreground)]">{{ $topup['user_name'] }}</td>
                                        <td class="px-4 py-3.5 font-medium tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                            {{ config('billing.currency_symbol', '$') }}{{ number_format($topup['amount'], 2) }}
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        @if ($variant === 'fulfillment' && auth()->user()?->can('view_fulfillments'))
            <section class="cf-panel cf-reveal cf-reveal-delay-2" data-test="recent-failed-fulfillments">
                <div class="flex items-center justify-between gap-3 border-b border-[var(--cf-border)] pb-4">
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_recent_failed_fulfillments') }}
                    </flux:heading>
                    <flux:button
                        size="sm"
                        variant="ghost"
                        :href="route('fulfillments', ['statusFilter' => \App\Enums\FulfillmentStatus::Failed->value])"
                        wire:navigate
                    >
                        {{ __('messages.view_all') }}
                    </flux:button>
                </div>

                @if (($inbox['recent_failed_fulfillments'] ?? []) === [])
                    <x-admin.ops-empty class="mt-4" icon="exclamation-triangle" :message="__('messages.admin_ops_no_failed_fulfillments')" />
                @else
                    <div class="cf-table-shell cf-table-row-hover mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.order_details') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.products') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.failure_reason') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_failed_fulfillments'] as $fulfillment)
                                    <tr wire:key="recent-failed-fulfillment-{{ $fulfillment['id'] }}">
                                        <td class="px-4 py-3.5">
                                            <a href="{{ $fulfillment['href'] }}" wire:navigate class="font-medium text-[var(--cf-primary)] hover:underline">
                                                {{ $fulfillment['order_number'] ?? '#'.$fulfillment['id'] }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3.5 text-[var(--cf-muted-foreground)]">{{ $fulfillment['product_name'] }}</td>
                                        <td class="px-4 py-3.5 text-[var(--cf-muted-foreground)]">{{ $fulfillment['last_error'] ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif

        @if (in_array($variant, ['full', 'fulfillment', 'orders'], true) && auth()->user()?->can('view_orders'))
            <section @class([
                'cf-panel cf-reveal cf-reveal-delay-3',
                'lg:col-span-2' => $variant === 'orders',
            ]) data-test="recent-attention-orders">
                <div class="flex items-center justify-between gap-3 border-b border-[var(--cf-border)] pb-4">
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_recent_failed_orders') }}
                    </flux:heading>
                    <flux:button size="sm" variant="ghost" :href="route('admin.orders.index')" wire:navigate>
                        {{ __('messages.view_all') }}
                    </flux:button>
                </div>

                @if (($inbox['recent_attention_orders'] ?? []) === [])
                    <x-admin.ops-empty class="mt-4" icon="shopping-bag" :message="__('messages.admin_ops_no_failed_orders')" />
                @else
                    <div class="cf-table-shell cf-table-row-hover mt-4 overflow-x-auto">
                        <table class="min-w-full text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.order_details') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.user') }}</th>
                                    <th class="px-4 py-3 text-start font-semibold">{{ __('messages.admin_ops_failed_items') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_attention_orders'] as $order)
                                    <tr wire:key="attention-order-{{ $order['id'] }}">
                                        <td class="px-4 py-3.5">
                                            <a
                                                href="{{ $order['href'] }}"
                                                wire:navigate
                                                class="font-medium text-[var(--cf-primary)] hover:underline"
                                            >
                                                {{ $order['order_number'] }}
                                            </a>
                                        </td>
                                        <td class="px-4 py-3.5 text-[var(--cf-muted-foreground)]">{{ $order['user_name'] }}</td>
                                        <td class="px-4 py-3.5">
                                            <flux:badge color="red">{{ $order['failed_count'] }}</flux:badge>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @endif
    </div>
</div>
