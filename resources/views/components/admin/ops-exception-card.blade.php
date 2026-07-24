@props([
    'card',
    'index' => 0,
])

@php
    $tone = match (true) {
        ($card['count'] ?? 0) === 0 => 'idle',
        ($card['severity'] ?? 'zinc') === 'red' => 'alert',
        ($card['severity'] ?? 'zinc') === 'amber' => 'warn',
        default => 'idle',
    };
    $delayClass = 'cf-reveal-delay-'.min(4, ($index % 4) + 1);
@endphp

<a
    href="{{ $card['href'] }}"
    wire:navigate
    wire:key="ops-card-{{ $card['key'] }}"
    data-test="ops-card-{{ $card['key'] }}"
    class="cf-ops-card cf-ops-card--{{ $tone }} cf-reveal {{ $delayClass }} group focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-[var(--cf-ring)] focus-visible:ring-offset-2 focus-visible:ring-offset-[var(--cf-card)]"
>
    <div class="cf-ops-card__icon">
        <flux:icon :name="$card['icon']" class="size-5" />
    </div>

    <div class="min-w-0 flex-1">
        <div class="flex items-start justify-between gap-2">
            <p class="text-3xl font-semibold leading-none tabular-nums tracking-tight text-[var(--cf-foreground)]">
                {{ number_format($card['count']) }}
            </p>
            <flux:icon
                name="chevron-right"
                class="cf-ops-card__chevron size-4 shrink-0 rtl:rotate-180"
            />
        </div>
        <p class="mt-2 text-sm font-medium leading-snug text-[var(--cf-foreground)]">
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
        <p class="mt-2 text-xs text-[var(--cf-muted-foreground)] transition-colors group-hover:text-[var(--cf-primary)]">
            {{ __('messages.admin_ops_view_queue') }}
        </p>
    </div>
</a>
