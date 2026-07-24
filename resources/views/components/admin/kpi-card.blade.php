@props([
    'kpi',
])

@php
    $icon = match ($kpi['key'] ?? '') {
        'orders' => 'shopping-bag',
        'revenue' => 'banknotes',
        'success_rate' => 'check-circle',
        'topups' => 'wallet',
        'gross_margin' => 'currency-dollar',
        'fulfillment_sla' => 'clock',
        default => 'chart-bar',
    };

    $tone = match ($kpi['key'] ?? '') {
        'revenue', 'gross_margin' => 'gold',
        'success_rate', 'fulfillment_sla' => 'emerald',
        default => 'sky',
    };
@endphp

<div
    class="cf-kpi-card cf-kpi-card--{{ $tone }} cf-reveal"
    data-test="admin-stat-{{ $kpi['key'] }}"
    wire:key="admin-stat-{{ $kpi['key'] }}"
>
    <div class="flex items-start justify-between gap-3">
        <div class="cf-kpi-card__icon">
            <flux:icon :name="$icon" class="size-4" />
        </div>
    </div>
    <p class="mt-4 text-[11px] font-semibold uppercase tracking-[0.14em] text-[var(--cf-muted-foreground)]">
        {{ $kpi['label'] }}
    </p>
    <p class="mt-1.5 text-3xl font-semibold leading-none tabular-nums tracking-tight text-[var(--cf-foreground)]">
        {{ $kpi['formatted'] }}
    </p>
    <p class="mt-2 text-xs leading-relaxed text-[var(--cf-muted-foreground)]">{{ $kpi['hint'] }}</p>
</div>
