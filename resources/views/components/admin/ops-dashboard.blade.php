@props([
    'inbox',
])

@php
    $variant = $inbox['variant'] ?? 'full';
@endphp

<div
    class="admin-fulfillments flex h-full w-full flex-1 flex-col gap-8"
    data-test="admin-ops-dashboard"
    data-variant="{{ $variant }}"
>
    <section class="cf-reveal rounded-2xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-5 shadow-sm">
        <div class="space-y-2">
            <p class="cf-display text-xs font-semibold tracking-[0.2em] text-[var(--cf-primary)] uppercase">
                {{ __('messages.nav_overview') }}
            </p>
            <flux:heading size="lg" class="cf-display tracking-tight text-[var(--cf-foreground)]">
                {{ __('messages.dashboard') }}
            </flux:heading>
            <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                {{ $inbox['intro'] ?? __('messages.admin_ops_intro') }}
            </flux:text>
        </div>

        @if ($inbox['all_clear'] ?? false)
            <div class="mt-4">
                <flux:callout variant="subtle" icon="check-circle" data-test="ops-all-clear">
                    {{ __('messages.admin_ops_all_clear_'.$variant) }}
                </flux:callout>
            </div>
        @endif

        @if ($variant === 'orders' && auth()->user()?->can('view_referrals'))
            <div class="mt-4">
                <flux:button
                    size="sm"
                    variant="primary"
                    :href="route('salesperson.dashboard')"
                    wire:navigate
                    data-test="supervisor-sales-dashboard-cta"
                >
                    {{ __('messages.admin_ops_salesperson_dashboard_cta') }}
                </flux:button>
            </div>
        @endif

        @if (($inbox['exception_cards'] ?? []) !== [])
            <div class="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @foreach ($inbox['exception_cards'] as $card)
                    <a
                        href="{{ $card['href'] }}"
                        wire:navigate
                        wire:key="ops-card-{{ $card['key'] }}"
                        data-test="ops-card-{{ $card['key'] }}"
                        @class([
                            'group flex items-start gap-3 rounded-xl border p-4 transition-colors duration-200',
                            'border-[var(--cf-border)] bg-[var(--cf-card-elevated)] hover:border-[var(--cf-primary)]/40 hover:bg-[var(--cf-card)]',
                        ])
                    >
                        <div @class([
                            'flex size-10 shrink-0 items-center justify-center rounded-lg',
                            'bg-red-500/10 text-red-600 dark:text-red-400' => $card['severity'] === 'red' && $card['count'] > 0,
                            'bg-amber-500/10 text-amber-600 dark:text-amber-400' => $card['severity'] === 'amber' && $card['count'] > 0,
                            'bg-[var(--cf-card)] text-[var(--cf-muted-foreground)]' => $card['count'] === 0 || $card['severity'] === 'zinc',
                        ])>
                            <flux:icon :name="$card['icon']" class="size-5" />
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]">
                                {{ number_format($card['count']) }}
                            </p>
                            <p class="mt-0.5 text-sm font-medium text-[var(--cf-foreground)]">
                                {{ $card['label'] }}
                            </p>
                            @if (($card['age_label'] ?? null) !== null)
                                <flux:badge
                                    size="sm"
                                    color="{{ $card['age_severity'] ?? 'zinc' }}"
                                    class="mt-2"
                                    data-test="ops-card-age-{{ $card['key'] }}"
                                >
                                    {{ $card['age_label'] }}
                                </flux:badge>
                            @endif
                            <p class="mt-1 text-xs text-[var(--cf-muted-foreground)] group-hover:text-[var(--cf-primary)]">
                                {{ __('messages.admin_ops_view_queue') }}
                            </p>
                        </div>
                    </a>
                @endforeach
            </div>
        @endif
    </section>

    @if ($inbox['queue_health'] ?? null)
        <section class="cf-reveal rounded-2xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-5 shadow-sm" data-test="queue-health-panel">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_queue_health') }}
                    </flux:heading>
                    <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                        {{ __('messages.admin_ops_queue_health_hint') }}
                    </flux:text>
                </div>
                @php
                    $load = $inbox['queue_health']['load'];
                    $loadColor = match ($load) {
                        'high' => 'red',
                        'medium' => 'amber',
                        default => 'green',
                    };
                @endphp
                <flux:badge color="{{ $loadColor }}">
                    {{ __('messages.admin_ops_load_'.$load) }}
                </flux:badge>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
                <div class="rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card-elevated)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--cf-muted-foreground)]">{{ __('messages.fulfillment_status_queued') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]">{{ number_format($inbox['queue_health']['queued']) }}</p>
                </div>
                <div class="rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card-elevated)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--cf-muted-foreground)]">{{ __('messages.fulfillment_status_processing') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]">{{ number_format($inbox['queue_health']['processing']) }}</p>
                </div>
                <div class="rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card-elevated)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--cf-muted-foreground)]">{{ __('messages.fulfillment_status_completed') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]">{{ number_format($inbox['queue_health']['completed']) }}</p>
                </div>
                <div class="rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card-elevated)] p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-[var(--cf-muted-foreground)]">{{ __('messages.admin_ops_active_supervisors') }}</p>
                    <p class="mt-1 text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]">{{ number_format($inbox['queue_health']['active_supervisors']) }}</p>
                </div>
                @if (($inbox['queue_health']['browser_needs_review'] ?? 0) > 0)
                    <div class="rounded-xl border border-amber-500/30 bg-amber-500/5 p-4">
                        <p class="text-xs font-semibold uppercase tracking-wide text-amber-700 dark:text-amber-400">{{ __('messages.admin_ops_automation_review') }}</p>
                        <p class="mt-1 text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]">{{ number_format($inbox['queue_health']['browser_needs_review']) }}</p>
                    </div>
                @endif
            </div>

            @if (($inbox['admin_alerts'] ?? []) !== [])
                <div class="mt-4 flex flex-col gap-2">
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

            <div class="mt-4">
                <flux:button
                    size="sm"
                    variant="ghost"
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
            <section class="cf-reveal rounded-2xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-5 shadow-sm lg:col-span-2" data-test="recent-orders">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_recent_orders') }}
                    </flux:heading>
                    <flux:button size="sm" variant="ghost" :href="route('admin.orders.index')" wire:navigate>
                        {{ __('messages.view_all') }}
                    </flux:button>
                </div>

                @if (($inbox['recent_orders'] ?? []) === [])
                    <flux:text class="mt-4 text-sm text-[var(--cf-muted-foreground)]">
                        {{ __('messages.admin_ops_no_recent_orders') }}
                    </flux:text>
                @else
                    <div class="cf-table-shell mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-[var(--cf-border)] text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.order_details') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.user') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.status') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_orders'] as $order)
                                    <tr wire:key="recent-order-{{ $order['id'] }}">
                                        <td class="px-3 py-3">
                                            <a href="{{ $order['href'] }}" wire:navigate class="font-medium text-[var(--cf-primary)] hover:underline">
                                                {{ $order['order_number'] }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-3 text-[var(--cf-muted-foreground)]">{{ $order['user_name'] }}</td>
                                        <td class="px-3 py-3">
                                            <flux:badge color="zinc">{{ __('messages.order_status_'.$order['status']) }}</flux:badge>
                                        </td>
                                        <td class="px-3 py-3 text-[var(--cf-foreground)]" dir="ltr">
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
            <section class="cf-reveal rounded-2xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-5 shadow-sm" data-test="recent-pending-refunds">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_recent_refunds') }}
                    </flux:heading>
                    <flux:button size="sm" variant="ghost" :href="route('refunds')" wire:navigate>
                        {{ __('messages.view_all') }}
                    </flux:button>
                </div>

                @if (($inbox['recent_pending_refunds'] ?? []) === [])
                    <flux:text class="mt-4 text-sm text-[var(--cf-muted-foreground)]">
                        {{ __('messages.admin_ops_no_pending_refunds') }}
                    </flux:text>
                @else
                    <div class="cf-table-shell mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-[var(--cf-border)] text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.order_details') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.user') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_pending_refunds'] as $refund)
                                    <tr wire:key="recent-refund-{{ $refund['id'] }}">
                                        <td class="px-3 py-3 text-[var(--cf-foreground)]">
                                            {{ $refund['order_number'] ?? '#'.$refund['id'] }}
                                        </td>
                                        <td class="px-3 py-3 text-[var(--cf-muted-foreground)]">{{ $refund['user_name'] }}</td>
                                        <td class="px-3 py-3 text-[var(--cf-foreground)]" dir="ltr">
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
            <section class="cf-reveal rounded-2xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-5 shadow-sm" data-test="recent-pending-topups">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_recent_topups') }}
                    </flux:heading>
                    <flux:button size="sm" variant="ghost" :href="route('topups')" wire:navigate>
                        {{ __('messages.view_all') }}
                    </flux:button>
                </div>

                @if (($inbox['recent_pending_topups'] ?? []) === [])
                    <flux:text class="mt-4 text-sm text-[var(--cf-muted-foreground)]">
                        {{ __('messages.admin_ops_no_pending_topups') }}
                    </flux:text>
                @else
                    <div class="cf-table-shell mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-[var(--cf-border)] text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.user') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.amount') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_pending_topups'] as $topup)
                                    <tr wire:key="recent-topup-{{ $topup['id'] }}">
                                        <td class="px-3 py-3 text-[var(--cf-muted-foreground)]">{{ $topup['user_name'] }}</td>
                                        <td class="px-3 py-3 text-[var(--cf-foreground)]" dir="ltr">
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
            <section class="cf-reveal rounded-2xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-5 shadow-sm" data-test="recent-failed-fulfillments">
                <div class="flex items-center justify-between gap-3">
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
                    <flux:text class="mt-4 text-sm text-[var(--cf-muted-foreground)]">
                        {{ __('messages.admin_ops_no_failed_fulfillments') }}
                    </flux:text>
                @else
                    <div class="cf-table-shell mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-[var(--cf-border)] text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.order_details') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.products') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.failure_reason') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_failed_fulfillments'] as $fulfillment)
                                    <tr wire:key="recent-failed-fulfillment-{{ $fulfillment['id'] }}">
                                        <td class="px-3 py-3">
                                            <a href="{{ $fulfillment['href'] }}" wire:navigate class="font-medium text-[var(--cf-primary)] hover:underline">
                                                {{ $fulfillment['order_number'] ?? '#'.$fulfillment['id'] }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-3 text-[var(--cf-muted-foreground)]">{{ $fulfillment['product_name'] }}</td>
                                        <td class="px-3 py-3 text-[var(--cf-muted-foreground)]">{{ $fulfillment['last_error'] ?? '—' }}</td>
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
                'cf-reveal rounded-2xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-5 shadow-sm',
                'lg:col-span-2' => $variant === 'orders',
            ]) data-test="recent-attention-orders">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.admin_ops_recent_failed_orders') }}
                    </flux:heading>
                    <flux:button size="sm" variant="ghost" :href="route('admin.orders.index')" wire:navigate>
                        {{ __('messages.view_all') }}
                    </flux:button>
                </div>

                @if (($inbox['recent_attention_orders'] ?? []) === [])
                    <flux:text class="mt-4 text-sm text-[var(--cf-muted-foreground)]">
                        {{ __('messages.admin_ops_no_failed_orders') }}
                    </flux:text>
                @else
                    <div class="cf-table-shell mt-4 overflow-x-auto">
                        <table class="min-w-full divide-y divide-[var(--cf-border)] text-sm">
                            <thead class="cf-table-head text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                                <tr>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.order_details') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.user') }}</th>
                                    <th class="px-3 py-2 text-start font-semibold">{{ __('messages.admin_ops_failed_items') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-[var(--cf-border)]">
                                @foreach ($inbox['recent_attention_orders'] as $order)
                                    <tr wire:key="attention-order-{{ $order['id'] }}">
                                        <td class="px-3 py-3">
                                            <a
                                                href="{{ $order['href'] }}"
                                                wire:navigate
                                                class="font-medium text-[var(--cf-primary)] hover:underline"
                                            >
                                                {{ $order['order_number'] }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-3 text-[var(--cf-muted-foreground)]">{{ $order['user_name'] }}</td>
                                        <td class="px-3 py-3">
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
