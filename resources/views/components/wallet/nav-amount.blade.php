@props([
    'display',
    'showCreditHint' => true,
    'stacked' => false,
])

@php
    /** @var \App\Support\CustomerWalletDisplay $display */
    // Stacked header chip: bare money only. Inline/CTA contexts keep Limit/Available labels.
    $hint = $showCreditHint
        ? ($stacked ? $display->navSecondaryAmount() : $display->navCreditHint())
        : null;
@endphp

<span
    {{ $attributes->class([
        'inline-flex tabular-nums',
        'flex-col items-end gap-0 leading-none' => $stacked,
        'items-baseline gap-1.5' => ! $stacked,
    ]) }}
    dir="ltr"
    title="{{ $display->navTitle() }}"
    data-test="wallet-nav-amount"
    data-wallet-tone="{{ $display->tone() }}"
>
    <span @class([
        $display->amountTextClass(),
        'text-sm font-semibold leading-tight' => $stacked,
    ])>{{ $display->formattedNavAmount() }}</span>
    @if ($hint)
        <span
            @class([
                'font-medium text-zinc-500 dark:text-zinc-400',
                'mt-0.5 text-[11px] leading-tight' => $stacked,
                'text-[10px]' => ! $stacked,
            ])
            data-test="wallet-nav-credit-hint"
        >{{ $hint }}</span>
    @endif
</span>
