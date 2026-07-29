@props(['item' => []])

@php
    $item = is_array($item) ? $item : [];
    $href = $item['href'] ?? null;
@endphp

<li class="py-3" data-test="refund-row" data-refund-status="{{ $item['status'] ?? '' }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            @if (is_string($href) && $href !== '')
                <a href="{{ $href }}" wire:navigate class="text-sm font-semibold text-zinc-900 hover:underline dark:text-zinc-100" dir="ltr">
                    {{ $item['public_reference'] ?? '' }}
                </a>
            @else
                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $item['public_reference'] ?? '' }}</p>
            @endif
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                <flux:badge color="{{ $item['badge_color'] ?? 'zinc' }}" size="sm">{{ $item['status_label'] ?? '' }}</flux:badge>
                <span class="ms-1">{{ $item['actor_label'] ?? '' }}</span>
            </p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                @if (! empty($item['order_number']))
                    <span dir="ltr">{{ $item['order_number'] }}</span>
                    <span aria-hidden="true"> · </span>
                @endif
                {{ $item['product_label'] ?? '' }}
            </p>
        </div>
        <span class="shrink-0 text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
            {{ $item['amount']['formatted'] ?? '—' }}
        </span>
    </div>
</li>
