@props([
    'orderId',
    /** @var array{label: string, color: string} $paymentStatus */
    'paymentStatus',
    'showPrices' => true,
    'formattedTotal' => null,
    'createdLabel',
])

<div class="grid gap-2 text-xs text-zinc-500 dark:text-zinc-400 sm:grid-cols-2 lg:grid-cols-4">
    <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200/80 bg-white/70 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
        <span>{{ __('messages.order') }}</span>
        <span class="font-medium text-zinc-700 dark:text-zinc-200">
            #{{ $orderId }}
        </span>
    </div>
    <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200/80 bg-white/70 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
        <span>{{ __('messages.payment_status') }}</span>
        <flux:badge color="{{ $paymentStatus['color'] }}" size="sm">
            {{ $paymentStatus['label'] }}
        </flux:badge>
    </div>
    <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200/80 bg-white/70 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
        <span>{{ __('messages.total') }}</span>
        @if ($showPrices)
        <span class="font-medium text-zinc-700 dark:text-zinc-200" dir="ltr">
            {{ $formattedTotal }}
        </span>
        @else
        <span class="font-medium text-zinc-500 dark:text-zinc-400">—</span>
        @endif
    </div>
    <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-200/80 bg-white/70 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-900/40">
        <span>{{ __('messages.created') }}</span>
        <span class="font-medium text-zinc-700 dark:text-zinc-200">
            {{ $createdLabel }}
        </span>
    </div>
</div>
