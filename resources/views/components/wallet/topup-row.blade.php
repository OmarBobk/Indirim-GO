@props(['item' => []])

@php
    $item = is_array($item) ? $item : [];
    $href = $item['href'] ?? null;
@endphp

<li class="py-3" data-test="topup-row" data-topup-status="{{ $item['status'] ?? '' }}">
    <div class="flex items-start justify-between gap-3">
        <div class="min-w-0">
            @if (is_string($href) && $href !== '')
                <a href="{{ $href }}" wire:navigate class="text-sm font-semibold text-zinc-900 hover:underline dark:text-zinc-100">
                    {{ $item['public_reference'] ?? '' }}
                </a>
            @else
                <p class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">{{ $item['public_reference'] ?? '' }}</p>
            @endif
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                <flux:badge color="{{ $item['badge_color'] ?? 'zinc' }}" size="sm">{{ $item['status_label'] ?? '' }}</flux:badge>
                <span class="ms-1">{{ $item['actor_label'] ?? '' }}</span>
            </p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ $item['payment_method_name'] ?? '' }}
                <span aria-hidden="true"> · </span>
                <time datetime="{{ $item['submitted_at'] ?? '' }}">{{ $item['updated_at_display'] ?? $item['submitted_at_display'] ?? '' }}</time>
            </p>
        </div>
        <span class="shrink-0 text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
            {{ $item['amount']['formatted'] ?? '—' }}
        </span>
    </div>
</li>
