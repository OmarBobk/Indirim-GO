@props([
    'href',
    'formattedTotal',
    'orderNumber',
    'formattedDate',
    /** @var array{label: string, color: string, progress: int} $status */
    'status',
    /** @var array{lines: int, units: int} $summary */
    'summary',
    /** @var array<int, array<string, mixed>> $lines */
    'lines',
    'showPrices' => true,
    /** @var array{kind: 'badge', label: string, color: string}|array{kind: 'action', label: string, orderId: int}|null $refundSummary */
    'refundSummary' => null,
])

@php
    $progressWidth = max(0, min(100, $status['progress'] ?? 0));
    $progressTint = match ($status['color'] ?? 'zinc') {
        'green' => 'bg-emerald-500 dark:bg-emerald-400',
        'blue' => 'bg-blue-500 dark:bg-blue-400',
        'amber' => 'bg-amber-400 dark:bg-amber-300',
        'red' => 'bg-red-500 dark:bg-red-400',
        default => 'bg-zinc-400 dark:bg-zinc-500',
    };
    $visibleLines = array_slice($lines, 0, 3);
    $hiddenLines = array_slice($lines, 3);
    $hasMoreItems = $hiddenLines !== [];
@endphp

{{-- Hierarchy: status → products → time → total/payment → actions --}}
<article
    class="overflow-hidden rounded-2xl border border-zinc-200/90 bg-white shadow-sm transition hover:border-zinc-300 hover:shadow-md dark:border-zinc-700/80 dark:bg-zinc-900 dark:hover:border-zinc-600"
    data-test="order-card"
    data-section="orders-card"
>
    <div class="h-1 w-full bg-zinc-100 dark:bg-zinc-800" aria-hidden="true">
        <div
            class="h-1 rounded-e-sm {{ $progressTint }} transition-all duration-300"
            style="width: {{ $progressWidth }}%"
        ></div>
    </div>

    <div class="space-y-4 p-4 sm:space-y-5 sm:p-5">
        {{-- 1. Status --}}
        <div class="flex flex-wrap items-center gap-2" data-test="order-card-status">
            <flux:badge color="{{ $status['color'] }}" class="text-xs font-semibold">
                {{ $status['label'] }}
            </flux:badge>
            @if (is_array($refundSummary) && ($refundSummary['kind'] ?? null) === 'badge' && isset($refundSummary['label'], $refundSummary['color']))
                <flux:badge color="{{ $refundSummary['color'] }}" class="text-xs font-semibold">
                    {{ $refundSummary['label'] }}
                </flux:badge>
            @endif
        </div>

        {{-- 2. Product / package recognition --}}
        <div
            x-data="{ showMoreItems: {{ $hasMoreItems ? 'false' : 'true' }} }"
            data-test="order-card-lines"
        >
            <div class="space-y-4">
                @foreach ($visibleLines as $line)
                    <x-orders.card-line :line="$line" :show-prices="$showPrices" :border-top="false" />
                @endforeach

                @if ($hasMoreItems)
                    <div x-show="showMoreItems" x-transition class="space-y-4">
                        @foreach ($hiddenLines as $line)
                            <x-orders.card-line :line="$line" :show-prices="$showPrices" :border-top="true" />
                        @endforeach
                    </div>

                    <button
                        type="button"
                        class="w-full rounded-lg border border-zinc-200 bg-zinc-50 px-3 py-2 text-sm font-medium text-zinc-700 transition hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800/80 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        @click.stop="showMoreItems = !showMoreItems"
                        data-test="order-card-toggle-more"
                    >
                        <span x-show="!showMoreItems">{{ __('messages.orders_card_show_more', ['count' => count($hiddenLines)]) }}</span>
                        <span x-show="showMoreItems" x-cloak>{{ __('messages.orders_card_show_less') }}</span>
                    </button>
                @endif
            </div>
        </div>

        {{-- 3. Time + order number --}}
        <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm" data-test="order-card-meta">
            <p class="font-medium text-zinc-800 dark:text-zinc-200">
                {{ $orderNumber }}
            </p>
            <p class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ $formattedDate }}
            </p>
        </div>

        {{-- 4. Total / payment --}}
        <div class="flex flex-wrap items-end justify-between gap-2 border-t border-zinc-100 pt-4 dark:border-zinc-800" data-test="order-card-total">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {{ __('messages.total') }}
            </p>
            @if ($showPrices)
                <p class="text-xl font-bold tabular-nums tracking-tight text-zinc-900 dark:text-zinc-100 sm:text-2xl" dir="ltr">
                    {{ $formattedTotal }}
                </p>
            @else
                <p class="text-xl font-bold text-zinc-500 dark:text-zinc-400">—</p>
            @endif
        </div>

        {{-- 5. Actions --}}
        <footer class="flex flex-col gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800 sm:flex-row sm:items-center sm:justify-between" data-test="order-card-actions">
            <p class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('messages.orders_card_summary', ['lines' => $summary['lines'], 'units' => $summary['units']]) }}
            </p>
            <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
                @if (is_array($refundSummary) && ($refundSummary['kind'] ?? 'badge') === 'action' && isset($refundSummary['orderId'], $refundSummary['label']))
                    <flux:button
                        type="button"
                        variant="primary"
                        size="sm"
                        wire:click.stop="requestRefundForOrder({{ (int) $refundSummary['orderId'] }})"
                        wire:loading.attr="disabled"
                        wire:target="requestRefundForOrder"
                        class="w-full !bg-accent !text-accent-foreground hover:!bg-accent-hover sm:w-auto"
                        data-test="order-card-request-refund"
                    >
                        {{ $refundSummary['label'] }}
                    </flux:button>
                @endif
                <flux:button
                    variant="primary"
                    icon:trailing="chevron-right"
                    :href="$href"
                    wire:navigate
                    class="w-full shrink-0 !bg-accent !text-accent-foreground hover:!bg-accent-hover sm:w-auto rtl:[&_[data-slot=icon]]:rotate-180"
                    data-test="order-card-cta"
                >
                    {{ __('messages.view_order') }}
                </flux:button>
            </div>
        </footer>
    </div>
</article>
