@props([
    'item' => [],
])

@php
    $item = is_array($item) ? $item : [];
    $isCredit = (bool) ($item['amount']['is_credit'] ?? false);
    $href = $item['href'] ?? null;
    $amountClass = $isCredit
        ? 'text-emerald-700 dark:text-emerald-400'
        : 'text-red-700 dark:text-red-400';
@endphp

<li
    {{ $attributes->class(['flex items-start justify-between gap-3 py-3']) }}
    data-test="financial-transaction-row"
    data-tx-direction="{{ $item['direction'] ?? '' }}"
>
    <div class="min-w-0">
        <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
            {{ $item['type_label'] ?? '' }}
            <span class="text-zinc-500 dark:text-zinc-400">· {{ $item['direction_label'] ?? '' }}</span>
        </p>
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
            <span>{{ $item['status_label'] ?? '' }}</span>
            <span aria-hidden="true"> · </span>
            <time datetime="{{ $item['occurred_at'] ?? '' }}">{{ $item['occurred_at_display'] ?? '' }}</time>
        </p>
        @if (! empty($item['reference_label']))
            <p class="mt-1 text-xs">
                @if (is_string($href) && $href !== '')
                    <a href="{{ $href }}" wire:navigate class="font-medium text-(--color-accent) hover:underline">
                        {{ __('messages.order_number') }}: {{ $item['reference_label'] }}
                    </a>
                @else
                    <span class="text-zinc-600 dark:text-zinc-400">{{ $item['reference_label'] }}</span>
                @endif
            </p>
        @elseif (is_string($href) && $href !== '')
            <p class="mt-1 text-xs">
                <a href="{{ $href }}" wire:navigate class="font-medium text-(--color-accent) hover:underline">
                    {{ __('messages.details') }}
                </a>
            </p>
        @endif
    </div>
    <span class="shrink-0 text-sm font-semibold tabular-nums {{ $amountClass }}" dir="ltr">
        {{ $item['amount']['formatted'] ?? '—' }}
        <span class="sr-only">{{ $item['direction_label'] ?? '' }}</span>
    </span>
</li>
