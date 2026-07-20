@props([])

<section
    class="mx-auto w-full max-w-7xl px-3 sm:px-0"
    data-section="customer-home-quick-actions"
    data-test="customer-home-quick-actions"
    aria-label="{{ __('main.home_quick_actions') }}"
>
    <div class="grid grid-cols-3 gap-2 sm:gap-3">
        <a
            href="{{ route('wallet.topup') }}"
            wire:navigate
            class="inline-flex flex-col items-center justify-center gap-1 rounded-xl border border-zinc-200 bg-white px-2 py-3 text-center text-xs font-medium text-zinc-700 shadow-sm transition hover:border-(--color-accent) dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
            data-event="home-quick-topup"
            data-test="customer-home-quick-topup"
        >
            <flux:icon icon="banknotes" class="size-5" />
            {{ __('messages.wallet_add_funds') }}
        </a>
        <a
            href="{{ route('orders.index') }}"
            wire:navigate
            class="inline-flex flex-col items-center justify-center gap-1 rounded-xl border border-zinc-200 bg-white px-2 py-3 text-center text-xs font-medium text-zinc-700 shadow-sm transition hover:border-(--color-accent) dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
            data-event="home-quick-orders"
            data-test="customer-home-quick-orders"
        >
            <flux:icon icon="shopping-bag" class="size-5" />
            {{ __('main.my_orders') }}
        </a>
        <a
            href="{{ route('cart') }}"
            wire:navigate
            class="inline-flex flex-col items-center justify-center gap-1 rounded-xl border border-zinc-200 bg-white px-2 py-3 text-center text-xs font-medium text-zinc-700 shadow-sm transition hover:border-(--color-accent) dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
            data-event="home-quick-cart"
            data-test="customer-home-quick-cart"
        >
            <flux:icon icon="shopping-cart" class="size-5" />
            {{ __('main.my_cart') }}
        </a>
    </div>
</section>
