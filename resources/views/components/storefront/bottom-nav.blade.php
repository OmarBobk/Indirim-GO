@php
    use App\Support\StorefrontShell;

    $items = StorefrontShell::bottomNavItems();
@endphp

<nav
    class="storefront-bottom-nav fixed inset-x-0 bottom-0 z-50 border-t border-zinc-200 bg-white/95 backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95 lg:hidden"
    aria-label="{{ __('main.primary_navigation') }}"
    data-test="storefront-bottom-nav"
    data-storefront-shell="bottom-nav"
>
    <ul class="mx-auto flex h-[3.75rem] max-w-7xl items-stretch justify-between gap-0 px-1">
        @foreach ($items as $item)
            <li class="min-w-0 flex-1" wire:key="bottom-nav-{{ $item['key'] }}">
                <a
                    href="{{ $item['href'] }}"
                    @if ($item['route'] !== 'login' && $item['route'] !== 'register')
                        wire:navigate
                    @endif
                    @class([
                        'relative flex h-full flex-col items-center justify-center gap-0.5 px-1 text-[10px] font-medium transition',
                        'text-(--color-accent-content) dark:text-(--color-accent)' => $item['active'],
                        'text-zinc-500 hover:text-zinc-800 dark:text-zinc-400 dark:hover:text-zinc-200' => ! $item['active'],
                    ])
                    @if ($item['active'])
                        aria-current="page"
                    @endif
                    data-test="bottom-nav-{{ $item['key'] }}"
                    data-event="{{ $item['event'] }}"
                    data-nav-active="{{ $item['active'] ? 'true' : 'false' }}"
                >
                    <span class="relative inline-flex size-6 items-center justify-center">
                        <flux:icon :icon="$item['icon']" class="size-5" />
                        @if ($item['badge'] === 'cart')
                            <span
                                x-data
                                x-cloak
                                x-show="$store.cart && $store.cart.count > 0"
                                class="absolute -top-1 -end-1 flex h-4 min-w-4 items-center justify-center rounded-full bg-(--color-accent) px-0.5 text-[10px] font-bold text-(--color-accent-foreground)"
                                x-text="$store.cart ? $store.cart.count : ''"
                                data-test="bottom-nav-cart-badge"
                                aria-hidden="true"
                            ></span>
                        @endif
                    </span>
                    <span class="max-w-full truncate">{{ $item['label'] }}</span>
                </a>
            </li>
        @endforeach
    </ul>
</nav>
