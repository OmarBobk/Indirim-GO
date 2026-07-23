@props([
    /** @var array<string, mixed> $item */
    'item',
    'showPrices' => true,
])

<div class="flex flex-wrap items-center justify-between gap-3">
    <div class="space-y-1">
        <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
            {{ $item['name'] }}
        </div>
        <div class="space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
            @if ($item['customAmount'] !== null)
                <div class="text-zinc-600 dark:text-zinc-300">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ $item['customAmount']['label'] }}:</span>
                    <span class="ms-0.5 font-medium tabular-nums text-zinc-800 dark:text-zinc-100" dir="ltr">{{ $item['customAmount']['amount'] }}</span>
                    @if ($item['customAmount']['unitLabel'])
                        <span class="ms-1">{{ $item['customAmount']['unitLabel'] }}</span>
                    @endif
                </div>
            @endif
            <div>{{ __('messages.quantity') }}: {{ $item['quantity'] }}</div>
        </div>
        @if ($item['packageName'])
            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                {{ $item['packageName'] }}
            </div>
        @endif
    </div>
    <flux:badge color="{{ $item['status']['color'] }}">
        {{ $item['status']['label'] }}
    </flux:badge>
</div>

<div class="mt-4 grid gap-2 text-xs text-zinc-500 dark:text-zinc-400 sm:grid-cols-3">
    <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-800/60">
        <span>{{ __('messages.unit_price') }}</span>
        @if ($showPrices)
        <span class="font-semibold text-zinc-900 dark:text-zinc-100" dir="ltr">
            {{ $item['formattedUnitPrice'] }}
        </span>
        @else
        <span class="font-semibold text-zinc-500 dark:text-zinc-400">—</span>
        @endif
    </div>
    <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-800/60">
        <span>{{ __('messages.line_total') }}</span>
        @if ($showPrices)
        <span class="font-semibold text-zinc-900 dark:text-zinc-100" dir="ltr">
            {{ $item['formattedLineTotal'] }}
        </span>
        @else
        <span class="font-semibold text-zinc-500 dark:text-zinc-400">—</span>
        @endif
    </div>
    <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-800/60">
        <span>{{ __('messages.payment_status') }}</span>
        <span class="font-semibold text-zinc-900 dark:text-zinc-100">
            {{ $item['paymentStatusLabel'] }}
        </span>
    </div>
</div>

@if ($item['requirements'] !== [])
    <div class="mt-4 rounded-xl border border-zinc-100 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-300">
        <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            {{ __('messages.requirements') }}
        </div>
        <div class="mt-2 grid gap-2">
            @foreach ($item['requirements'] as $entry)
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-zinc-500 dark:text-zinc-400">{{ $entry['label'] }}</span>
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $entry['value'] }}
                    </span>
                </div>
            @endforeach
        </div>
    </div>
@endif
