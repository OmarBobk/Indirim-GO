@props([
    'item' => [],
])

@php
    $item = is_array($item) ? $item : [];
    $isCredit = (bool) ($item['amount']['is_credit'] ?? false);
    $amountClass = $isCredit
        ? 'text-emerald-700 dark:text-emerald-400'
        : 'text-red-700 dark:text-red-400';
    $href = $item['href'] ?? null;
    $sourceHref = $item['source_href'] ?? null;
@endphp

<li
    {{ $attributes->class(['flex items-start gap-3 py-3']) }}
    data-test="financial-ledger-row"
    data-tx-type="{{ $item['type'] ?? '' }}"
    data-tx-direction="{{ $item['direction'] ?? '' }}"
>
    <div class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300" aria-hidden="true">
        <flux:icon :name="$item['icon'] ?? 'banknotes'" variant="mini" class="size-4" />
    </div>

    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
            @if (is_string($href) && $href !== '')
                <a
                    href="{{ $href }}"
                    wire:navigate
                    class="hover:underline"
                    data-test="financial-ledger-detail-link"
                >
                    {{ $item['type_label'] ?? '' }}
                </a>
            @else
                {{ $item['type_label'] ?? '' }}
            @endif
            <span class="font-normal text-zinc-500 dark:text-zinc-400">· {{ $item['direction_label'] ?? '' }}</span>
        </p>
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
            <span class="font-mono" dir="ltr">{{ $item['public_reference'] ?? '' }}</span>
            <span aria-hidden="true"> · </span>
            <time datetime="{{ $item['occurred_at'] ?? '' }}">{{ $item['occurred_at_display'] ?? '' }}</time>
        </p>
        @if (! empty($item['description']))
            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">{{ $item['description'] }}</p>
        @endif
        <div class="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs">
            @if (is_string($href) && $href !== '' && ! empty($item['destination_label']))
                <a
                    href="{{ $href }}"
                    wire:navigate
                    class="font-medium text-(--color-accent) hover:underline"
                    data-test="financial-ledger-destination"
                >
                    {{ $item['destination_label'] }}
                </a>
            @endif
            @if (is_string($sourceHref) && $sourceHref !== '' && ! empty($item['source_destination_label']))
                <a
                    href="{{ $sourceHref }}"
                    wire:navigate
                    class="font-medium text-zinc-600 hover:underline dark:text-zinc-300"
                    data-test="financial-ledger-source-destination"
                >
                    {{ $item['source_destination_label'] }}
                </a>
            @endif
        </div>
    </div>

    <div class="shrink-0 text-end">
        <p class="text-sm font-semibold tabular-nums {{ $amountClass }}" dir="ltr">
            {{ $item['amount']['formatted'] ?? '—' }}
            <span class="sr-only">{{ $item['direction_label'] ?? '' }}</span>
        </p>
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
            {{ $item['status_label'] ?? '' }}
        </p>
    </div>
</li>
