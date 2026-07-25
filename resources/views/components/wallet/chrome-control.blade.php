@props([
    /** @var \App\Support\CustomerWalletDisplay|null $display */
    'display' => null,
])

@php
    $pricesVisible = \App\Models\WebsiteSetting::getPricesVisible();
    $showAmount = $pricesVisible && $display !== null;
@endphp

@if ($showAmount)
    {{-- Shared header chrome: amount chip + popover. Used on desktop utilities and mobile top bar. --}}
    <div
        {{ $attributes->class(['relative shrink-0']) }}
        x-data="{ open: false }"
        x-on:keydown.escape.window="open = false"
        x-on:scroll.window="open = false"
        x-on:click.outside="open = false"
        data-test="wallet-chrome-control"
    >
        <button
            type="button"
            class="storefront-shell-icon-btn inline-flex min-h-11 min-w-11 shrink-0 items-center justify-center rounded-md px-1.5 py-0.5 transition-[background-color,color,transform] duration-150 hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 focus-visible:ring-offset-2 focus-visible:ring-offset-white motion-reduce:transition-none dark:hover:bg-zinc-800 dark:focus-visible:ring-offset-zinc-900"
            aria-label="{{ __('main.wallet') }} — {{ $display->navTitle() }}"
            aria-expanded="false"
            aria-haspopup="dialog"
            x-bind:aria-expanded="open.toString()"
            x-on:click="open = ! open"
            data-test="wallet-balance"
            data-event="top-nav-wallet"
            data-wallet-tone="{{ $display->tone() }}"
            data-wallet-cta-badge="{{ $display->formattedCtaBadge() }}"
        >
            <x-wallet.nav-amount
                :display="$display"
                stacked
                class="min-w-0"
            />
        </button>
        <div
            x-cloak
            x-show="open"
            x-transition:enter="transition ease-out duration-150 motion-reduce:duration-0"
            x-transition:enter-start="opacity-0"
            x-transition:enter-end="opacity-100"
            x-transition:leave="transition ease-in duration-100 motion-reduce:duration-0"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            class="absolute end-0 top-full z-50 mt-2 w-64 max-w-[min(16rem,calc(100vw-1.5rem))] overscroll-contain rounded-xl border border-zinc-200 bg-white p-3 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
            role="dialog"
            aria-label="{{ __('main.wallet') }}"
            data-test="wallet-chrome-popover"
        >
            <x-wallet.chrome-summary :display="$display" />
            <div class="mt-3">
                <flux:button
                    href="{{ route('wallet') }}"
                    variant="primary"
                    size="sm"
                    class="w-full justify-center !bg-accent !text-accent-foreground hover:!bg-accent-hover"
                    wire:navigate
                    data-test="wallet-chrome-open"
                >
                    {{ __('main.add_sufficient') }}
                </flux:button>
            </div>
        </div>
    </div>
@else
    <a
        {{ $attributes->class([
            'storefront-shell-icon-btn inline-flex size-11 shrink-0 items-center justify-center rounded-full text-zinc-700 transition-[background-color,color,transform] duration-150 hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 focus-visible:ring-offset-2 focus-visible:ring-offset-white motion-reduce:transition-none dark:text-zinc-200 dark:hover:bg-zinc-800 dark:focus-visible:ring-offset-zinc-900',
        ]) }}
        href="{{ route('wallet') }}"
        wire:navigate
        aria-label="{{ __('main.wallet') }}"
        data-test="wallet-balance"
        data-event="top-nav-wallet"
    >
        <flux:icon icon="wallet" class="size-5 text-zinc-500 dark:text-zinc-300" aria-hidden="true" />
    </a>
@endif
