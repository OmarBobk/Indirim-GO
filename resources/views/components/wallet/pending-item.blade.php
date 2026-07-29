@props([
    'item' => [],
])

@php
    $item = is_array($item) ? $item : [];
    $href = $item['href'] ?? null;
@endphp

<li
    {{ $attributes->class(['rounded-xl border border-zinc-100 bg-zinc-50 px-3 py-3 dark:border-zinc-800 dark:bg-zinc-800/50']) }}
    data-test="financial-pending-item"
    data-pending-kind="{{ $item['kind'] ?? '' }}"
    data-pending-actor="{{ $item['actor'] ?? '' }}"
>
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            @if (is_string($href) && $href !== '')
                <a href="{{ $href }}" wire:navigate class="text-sm font-medium text-zinc-900 hover:underline dark:text-zinc-100">
                    {{ $item['title'] ?? '' }}
                </a>
            @else
                <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $item['title'] ?? '' }}</p>
            @endif
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                <flux:badge color="{{ $item['badge_color'] ?? 'zinc' }}" size="sm" class="align-middle">
                    {{ $item['actor_label'] ?? '' }}
                </flux:badge>
                @if (! empty($item['reference_label']))
                    <span class="ms-1">· {{ $item['reference_label'] }}</span>
                @endif
            </p>
            @if (! empty($item['customer_safe_reason']))
                <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-400">{{ $item['customer_safe_reason'] }}</p>
            @endif
        </div>
        @if (($item['amount'] ?? null) !== null)
            <span class="shrink-0 text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                {{ $item['amount']['formatted'] ?? '—' }}
            </span>
        @endif
    </div>
</li>
