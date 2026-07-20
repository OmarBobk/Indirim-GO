@props([
    'walletDisplay' => null,
])

@php
    /** @var \App\Support\CustomerWalletDisplay|null $walletDisplay */
    $pricesVisible = \App\Models\WebsiteSetting::getPricesVisible();
@endphp

<header
    class="storefront-mobile-top fixed inset-x-0 top-0 z-50 border-b border-zinc-200 bg-white/95 backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95 lg:hidden"
    data-test="storefront-mobile-top"
    data-storefront-shell="mobile-top"
    x-data="{ searchOpen: false }"
    x-on:keydown.escape.window="searchOpen = false"
>
    <div class="mx-auto flex h-[3.25rem] max-w-7xl items-center gap-2 px-3">
        <x-app-brand-logo
            wire:navigate
            class="min-w-0 shrink"
            data-event="top-nav-logo"
        />

        <div class="ms-auto flex items-center gap-1.5 shrink-0">
            @auth
                @if ($pricesVisible && $walletDisplay)
                    <a
                        href="{{ route('wallet') }}"
                        wire:navigate
                        class="inline-flex max-w-[9.5rem] items-center rounded-lg px-1.5 py-1 transition hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 dark:hover:bg-zinc-800"
                        aria-label="{{ __('main.wallet') }} — {{ $walletDisplay->navTitle() }}"
                        data-test="wallet-balance"
                        data-event="wallet-chip"
                        title="{{ $walletDisplay->navTitle() }}"
                    >
                        <x-wallet.nav-amount
                            :display="$walletDisplay"
                            stacked
                            class="min-w-0"
                        />
                    </a>
                @else
                    <a
                        href="{{ route('wallet') }}"
                        wire:navigate
                        class="inline-flex size-10 items-center justify-center rounded-full text-zinc-700 transition hover:bg-zinc-100 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        aria-label="{{ __('main.wallet') }}"
                        data-test="wallet-balance"
                        data-event="wallet-chip"
                    >
                        <flux:icon icon="wallet" class="size-5" />
                    </a>
                @endif
            @endauth

            <button
                type="button"
                class="inline-flex size-10 items-center justify-center rounded-full text-zinc-700 transition hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 dark:text-zinc-200 dark:hover:bg-zinc-800"
                aria-label="{{ __('main.search_packages_placeholder') }}"
                aria-expanded="false"
                x-bind:aria-expanded="searchOpen.toString()"
                data-test="mobile-search-toggle"
                data-event="top-nav-search"
                x-on:click="
                    searchOpen = ! searchOpen;
                    if (searchOpen) {
                        $nextTick(() => document.getElementById('storefront-mobile-search-input')?.focus());
                    }
                "
            >
                <flux:icon icon="magnifying-glass" class="size-5" x-show="! searchOpen" />
                <flux:icon icon="x-mark" class="size-5" x-cloak x-show="searchOpen" />
            </button>
        </div>
    </div>

    <div
        x-cloak
        x-show="searchOpen"
        x-transition.opacity.duration.150ms
        class="absolute inset-x-0 top-full border-b border-zinc-200 bg-white px-3 py-2 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
        data-test="mobile-search-panel"
        x-on:click.outside="if (! $event.target.closest('[data-event=top-nav-search]')) searchOpen = false"
    >
        <div class="mx-auto max-w-7xl">
            <x-storefront.package-search input-id="storefront-mobile-search-input" />
        </div>
    </div>
</header>
