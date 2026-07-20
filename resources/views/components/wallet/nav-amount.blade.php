@props([
    'display',
    'showCreditHint' => true,
])

@php
    /** @var \App\Support\CustomerWalletDisplay $display */
@endphp

<span
    {{ $attributes->class(['inline-flex items-baseline gap-1.5 tabular-nums']) }}
    dir="ltr"
    title="{{ $display->navTitle() }}"
    data-test="wallet-nav-amount"
    data-wallet-tone="{{ $display->tone() }}"
>
    <span class="{{ $display->amountTextClass() }}">
        {{ $display->formattedNavAmount() }}
    </span>
    @if ($showCreditHint && $display->navCreditHint())
        <span
            class="hidden text-[10px] font-medium text-zinc-500 dark:text-zinc-400 lg:inline"
            data-test="wallet-nav-credit-hint"
        >
            {{ $display->navCreditHint() }}
        </span>
    @endif
</span>
