@props([])

@php
    /** @var \App\Support\CustomerWalletDisplay|null $display */
    $display = null;
    $pricesVisible = \App\Models\WebsiteSetting::getPricesVisible();

    if (auth()->check()) {
        $wallet = \App\Models\Wallet::forUser(auth()->user());
        $display = \App\Support\CustomerWalletDisplay::for($wallet, auth()->user());
    }
@endphp

@if ($display)
    <section
        class="mx-auto w-full max-w-7xl px-3 sm:px-0"
        data-section="customer-home-wallet"
        data-test="customer-home-wallet"
        aria-labelledby="customer-home-wallet-heading"
    >
        <div
            class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5"
            data-wallet-tone="{{ $display->tone() }}"
        >
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
                <div class="min-w-0 flex-1 space-y-3">
                    <div class="flex items-center justify-between gap-3">
                        <h2
                            id="customer-home-wallet-heading"
                            class="text-sm font-semibold text-zinc-900 dark:text-zinc-100"
                        >
                            {{ __('main.wallet') }}
                        </h2>
                        <a
                            href="{{ route('wallet.topup') }}"
                            wire:navigate
                            class="inline-flex shrink-0 items-center rounded-lg bg-(--color-accent) px-3 py-1.5 text-xs font-semibold text-(--color-accent-foreground) transition hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40"
                            data-test="customer-home-wallet-topup"
                            data-event="home-wallet-topup"
                        >
                            {{ __('messages.wallet_add_funds') }}
                        </a>
                    </div>

                    @if ($pricesVisible)
                        <x-wallet.chrome-summary :display="$display" />
                    @else
                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('main.wallet') }}
                        </p>
                    @endif
                </div>

                <a
                    href="{{ route('wallet') }}"
                    wire:navigate
                    class="inline-flex items-center justify-center rounded-lg border border-zinc-200 px-3 py-2 text-xs font-medium text-zinc-700 transition hover:bg-zinc-50 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 dark:border-zinc-600 dark:text-zinc-200 dark:hover:bg-zinc-800"
                    data-test="customer-home-wallet-details"
                    data-event="home-wallet-details"
                >
                    {{ __('messages.view_details') }}
                </a>
            </div>
        </div>
    </section>
@endif
