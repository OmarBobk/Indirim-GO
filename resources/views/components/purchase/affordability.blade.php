@props([
    'available',
    'total' => null,
    'needsFunds' => false,
    'topupUrl' => null,
    'compact' => false,
])

@php
    $availableFloat = $available !== null ? (float) $available : null;
    $totalFloat = $total !== null ? (float) $total : null;
    $shortfall = ($availableFloat !== null && $totalFloat !== null)
        ? max(0, round($totalFloat - $availableFloat, 2))
        : null;
    $canAfford = $shortfall === null ? null : $shortfall <= 0;
    $resolvedTopupUrl = $topupUrl ?? (
        $shortfall !== null && $shortfall > 0
            ? route('wallet.topup', ['amount' => number_format($shortfall, 2, '.', '')])
            : route('wallet.topup')
    );
@endphp

<div
    {{ $attributes->class([
        'rounded-xl border px-3 py-2.5',
        'border-emerald-200 bg-emerald-50/80 dark:border-emerald-800/60 dark:bg-emerald-950/30' => $canAfford === true,
        'border-amber-200 bg-amber-50/80 dark:border-amber-800/60 dark:bg-amber-950/30' => $canAfford === false || $needsFunds,
        'border-zinc-200 bg-zinc-50/80 dark:border-zinc-700 dark:bg-zinc-800/50' => $canAfford === null && ! $needsFunds,
    ]) }}
    data-test="purchase-affordability"
    @if ($availableFloat !== null)
        data-available="{{ number_format($availableFloat, 2, '.', '') }}"
    @endif
    @if ($totalFloat !== null)
        data-total="{{ number_format($totalFloat, 2, '.', '') }}"
    @endif
>
    <div class="flex items-center justify-between gap-3 text-sm">
        <span class="font-medium text-zinc-600 dark:text-zinc-300">
            {{ __('messages.cart_available_to_spend') }}
        </span>
        <span
            @class([
                'font-semibold tabular-nums',
                'text-emerald-700 dark:text-emerald-400' => ($availableFloat ?? 0) > 0,
                'text-zinc-900 dark:text-zinc-100' => ($availableFloat ?? 0) <= 0,
            ])
            dir="ltr"
        >
            @if ($availableFloat !== null)
                {{ \App\Support\FrontendMoney::for(auth()->user())->format($availableFloat, 'USD', 2) }}
            @else
                —
            @endif
        </span>
    </div>

    @if ($needsFunds || $canAfford === false)
        <div class="mt-2 flex flex-wrap items-center justify-between gap-2">
            <p class="text-xs font-medium text-amber-800 dark:text-amber-200">
                {{ __('messages.purchase_need_more_funds_hint') }}
            </p>
            <a
                href="{{ $resolvedTopupUrl }}"
                wire:navigate
                class="text-xs font-semibold text-(--color-accent) underline-offset-2 hover:underline"
                data-test="purchase-add-funds-link"
            >
                {{ __('messages.cart_need_more_funds') }}
            </a>
        </div>
    @elseif (! $compact)
        <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
            {{ __('messages.purchase_wallet_covers_order') }}
        </p>
    @endif
</div>
