@props([])

@php
    $orders = auth()->check()
        ? \App\Support\CustomerHomeRecentOrders::forUser(auth()->user())
        : [];
@endphp

<section
    class="mx-auto w-full max-w-7xl px-3 sm:px-0"
    data-section="customer-home-recent-orders"
    data-test="customer-home-recent-orders"
    aria-labelledby="customer-home-recent-orders-heading"
>
    <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
        <div class="mb-3 flex items-center justify-between gap-3">
            <h2
                id="customer-home-recent-orders-heading"
                class="text-base font-semibold text-zinc-900 dark:text-zinc-100"
            >
                {{ __('main.home_recent_orders') }}
            </h2>
            <a
                href="{{ route('orders.index') }}"
                wire:navigate
                class="text-xs font-medium text-(--color-accent-content) dark:text-(--color-accent)"
                data-test="customer-home-recent-orders-all"
                data-event="home-recent-orders-all"
            >
                {{ __('main.home_view_all_orders') }}
            </a>
        </div>

        @if ($orders === [])
            <div
                class="rounded-xl border border-dashed border-zinc-200 px-3 py-5 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
                data-test="customer-home-recent-orders-empty"
            >
                {{ __('main.home_recent_orders_empty') }}
            </div>
        @else
            <ul class="divide-y divide-zinc-100 dark:divide-zinc-800" data-test="customer-home-recent-orders-list">
                @foreach ($orders as $order)
                    <li wire:key="home-order-{{ $order['id'] }}">
                        <a
                            href="{{ $order['href'] }}"
                            wire:navigate
                            class="flex items-center justify-between gap-3 py-3 transition hover:bg-zinc-50/80 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 dark:hover:bg-zinc-800/50"
                            data-test="customer-home-recent-order"
                            data-event="home-recent-order"
                            data-order-status="{{ $order['status'] }}"
                        >
                            <div class="min-w-0">
                                <p class="truncate text-sm font-medium text-zinc-900 dark:text-zinc-100">
                                    {{ $order['order_number'] }}
                                </p>
                                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $order['status_label'] }}
                                </p>
                            </div>
                            <p class="shrink-0 text-sm font-semibold tabular-nums text-zinc-800 dark:text-zinc-100" dir="ltr">
                                {{ $order['total_label'] }}
                            </p>
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
</section>
