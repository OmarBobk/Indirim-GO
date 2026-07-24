<!DOCTYPE html>
@php
    $isRtl = app()->isLocale('ar');
    $direction = $isRtl ? 'rtl' : 'ltr';
    $walletDisplay = null;

    if (auth()->check()) {
        $wallet = \App\Models\Wallet::forUser(auth()->user());
        $walletDisplay = \App\Support\CustomerWalletDisplay::for($wallet, auth()->user());
    }
@endphp
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ $direction }}" class="dark">
    <head>
        @include('partials.frontend.head')
    </head>
    <body
        class="storefront-shell min-h-screen bg-white dark:bg-zinc-900"
        style="--bg-pattern: url('{{ asset('images/background-pattern.jpg') }}'); --bg-pattern-dark: url('{{ asset('images/background-pattern-dark.jpg') }}');"
        x-data
        x-on:cart-custom-amount-priced.window="
            if ($store.cart) {
                if ($event.detail?.price !== undefined) {
                    $store.cart.applyCustomAmountPrice($event.detail);
                }
                $store.cart.setCustomAmountError($event.detail);
            }
        "
        data-storefront-shell="root"
    >
        <script>window.__addToCartMessageTemplate = @json(__('main.add_to_cart_for'));</script>

        <x-storefront.mobile-top-bar />

        <div class="hidden lg:block" data-storefront-shell="desktop-header">
        <flux:header
            sticky
            class="!block !p-0 fixed top-0 start-0 end-0 z-50 w-full transition-all duration-300"
            x-data="{ isScrolled: false }"
            x-init="window.addEventListener('scroll', () => { isScrolled = window.scrollY > 10;})"
        >
            @guest
            <div
                class="bg-accent px-3"
                data-test="frontend-announcement-bar"
                role="region"
                aria-label="{{ __('main.announcement_welcome') }}"
            >
                <div
                    class="mx-auto flex max-w-7xl items-center justify-between gap-3 py-2 text-base font-semibold text-accent-foreground"
                >
                    <p class="text-start font-sans">
                        {{ __('main.announcement_welcome') }}
                    </p>
                </div>
            </div>
            @endguest

            <div
                class="border-b border-zinc-200 bg-white px-3 py-3 transition-all duration-300 dark:border-zinc-700 dark:!bg-zinc-900"
                x-bind:class="isScrolled ? 'shadow-lg' : ''"
            >
                <div class="mx-auto w-full h-full [:where(&)]:max-w-7xl  items-center">


                <div class="flex flex-nowrap items-center justify-between gap-3 sm:gap-4 w-full mb-3 sm:mb-0">
                    <!-- Logo -->
                    <x-app-brand-logo wire:navigate class="order-1 shrink-0" />

                    {{-- Authenticated home: Command Zone owns search (single owner). --}}
                    @unless (auth()->check() && request()->routeIs('home'))
                        <!-- Search Bar (Alpine + JSON API; packages only, inline results) -->
                        <div class="min-w-0 flex-1 max-w-3xl mx-auto order-3 sm:order-2" data-test="desktop-header-package-search">
                            <x-storefront.package-search />
                        </div>
                    @endunless

                    <!-- Action Icons -->
                    <div class="storefront-shell-utilities order-2 sm:order-3" data-test="desktop-shell-utilities">
                        <x-admin.usd-try-rate-panel variant="storefront" />

                        <livewire:cart.dropdown />

                        @auth
                        <livewire:notification-bell-dropdown />
                        @endauth

                        <div x-data x-on:scroll.window="const p = $el.querySelector('[popover]'); if (p) { try { p.hidePopover(); } catch (_) {} }">
                        <flux:dropdown position="bottom" align="end">
                            <flux:button
                                variant="ghost"
                                icon="user"
                                class="storefront-shell-icon-btn !h-10 !w-10 !p-0 [&>div>svg]:size-5 !text-zinc-700 dark:!text-zinc-300
                                hover:cursor-pointer hover:!bg-zinc-200 dark:hover:!bg-zinc-800 rounded-full transition-colors
                                focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 focus-visible:ring-offset-2
                                focus-visible:ring-offset-white dark:focus-visible:ring-offset-zinc-900"
                                aria-label="{{ __('main.account_menu') }}"
                            />
                            <flux:navmenu class="min-w-48 rounded-xl border border-zinc-200 bg-white p-1 shadow-lg dark:border-zinc-700 dark:bg-zinc-900">
                                @auth
                                    <flux:navmenu.item
                                        icon="user"
                                        href="{{ route('account') }}"
                                        wire:navigate
                                        class="rounded-lg !text-zinc-700 hover:!bg-zinc-100 focus-visible:!bg-zinc-100 dark:!text-zinc-200 dark:hover:!bg-zinc-800 dark:focus-visible:!bg-zinc-800"
                                        data-test="desktop-account-hub"
                                    >
                                        {{ __('main.account') }}
                                    </flux:navmenu.item>
                                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                                        @csrf
                                        <flux:menu.item
                                            as="button"
                                            type="submit"
                                            icon="{{ $isRtl ? 'arrow-left-start-on-rectangle' : 'arrow-right-start-on-rectangle' }}"
                                            class="rounded-lg !text-zinc-700 hover:!bg-zinc-100 focus-visible:!bg-zinc-100 dark:!text-zinc-200 dark:hover:!bg-zinc-800 dark:focus-visible:!bg-zinc-800"
                                            data-test="logout-button"
                                        >
                                            {{ __('main.logout') }}
                                        </flux:menu.item>
                                    </form>
                                @else
                                    <flux:navmenu.item
                                        icon="user"
                                        href="{{ route('login') }}"
                                        class="rounded-lg !text-zinc-700 hover:!bg-zinc-100 focus-visible:!bg-zinc-100 dark:!text-zinc-200 dark:hover:!bg-zinc-800 dark:focus-visible:!bg-zinc-800"
                                    >
                                        {{ __('main.login') }}
                                    </flux:navmenu.item>

                                    @if (Route::has('register'))
                                        <flux:navmenu.item
                                            icon="plus"
                                            href="{{ route('register') }}"
                                            class="rounded-lg !text-zinc-700 hover:!bg-zinc-100 focus-visible:!bg-zinc-100 dark:!text-zinc-200 dark:hover:!bg-zinc-800 dark:focus-visible:!bg-zinc-800"
                                        >
                                            {{ __('main.register') }}
                                        </flux:navmenu.item>
                                    @endif
                                @endauth
                            </flux:navmenu>
                        </flux:dropdown>
                        </div>

                        @auth
                            <span class="storefront-shell-utilities__divider" aria-hidden="true"></span>
                            @if (\App\Models\WebsiteSetting::getPricesVisible() && $walletDisplay)
                                <div
                                    class="relative shrink-0"
                                    x-data="{ open: false }"
                                    x-on:keydown.escape.window="open = false"
                                    x-on:scroll.window="open = false"
                                    x-on:click.outside="open = false"
                                >
                                    <button
                                        type="button"
                                        class="storefront-shell-icon-btn inline-flex shrink-0 items-center justify-center rounded-md px-1.5 py-0.5 transition hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:hover:bg-zinc-800 dark:focus-visible:ring-offset-zinc-900"
                                        aria-label="{{ __('main.wallet') }} — {{ $walletDisplay->navTitle() }}"
                                        aria-expanded="false"
                                        aria-haspopup="dialog"
                                        x-bind:aria-expanded="open.toString()"
                                        x-on:click="open = ! open"
                                        data-test="wallet-balance"
                                        data-wallet-tone="{{ $walletDisplay->tone() }}"
                                        data-wallet-cta-badge="{{ $walletDisplay->formattedCtaBadge() }}"
                                    >
                                        <x-wallet.nav-amount
                                            :display="$walletDisplay"
                                            stacked
                                            class="min-w-0"
                                        />
                                    </button>
                                    <div
                                        x-cloak
                                        x-show="open"
                                        x-transition.opacity.duration.150ms
                                        class="absolute end-0 top-full z-50 mt-2 w-64 max-w-[min(16rem,calc(100vw-1.5rem))] rounded-xl border border-zinc-200 bg-white p-3 shadow-lg dark:border-zinc-700 dark:bg-zinc-900"
                                        role="dialog"
                                        aria-label="{{ __('main.wallet') }}"
                                        data-test="wallet-chrome-popover"
                                    >
                                        <x-wallet.chrome-summary :display="$walletDisplay" />
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
                                    href="{{ route('wallet') }}"
                                    wire:navigate
                                    class="storefront-shell-icon-btn inline-flex size-10 items-center justify-center rounded-full text-zinc-700 transition hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 dark:text-zinc-200 dark:hover:bg-zinc-800"
                                    aria-label="{{ __('main.wallet') }}"
                                    data-test="wallet-balance"
                                >
                                    <flux:icon icon="wallet" class="size-4 text-zinc-500 dark:text-zinc-300" />
                                </a>
                            @endif
                        @endauth

                        <span class="storefront-shell-utilities__divider" aria-hidden="true"></span>
                        <flux:button
                            x-data
                            x-on:click="$flux.dark = ! $flux.dark"
                            icon="moon"
                            variant="subtle"
                            class="storefront-shell-icon-btn shrink-0"
                            aria-label="{{ __('main.toggle_theme') }}"
                            data-test="desktop-theme-toggle"
                        />
                    </div>
                </div>
                <flux:separator class="my-3 sm:block hidden" />

                @php
                    $browseNavItems = \App\Support\StorefrontShell::browseNavItems();
                @endphp
                <nav
                    x-data="categoryNav()"
                    x-init="init()"
                    class=" border-zinc-200 dark:border-zinc-800 dark:bg-zinc-900"
                    aria-label="{{ __('main.browse_navigation') }}"
                    data-test="storefront-browse-nav"
                    data-storefront-shell="browse-nav"
                >
                    <div class="mx-auto max-w-7xl ">
                        <div class="relative">
                            <!-- Left button (desktop only) -->
                            <button
                                type="button"
                                class="cursor-pointer hidden lg:flex absolute start-0 top-1/2 -translate-y-1/2 z-20 h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white dark:bg-zinc-900 dark:border-zinc-700 shadow-sm hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-30 disabled:cursor-not-allowed"
                                x-on:click="scrollByLogical(-320)"
                                x-bind:disabled="atStart"
                                aria-label="Scroll previous"
                            >
                                <flux:icon icon="chevron-left" class="size-5 text-zinc-700 dark:text-zinc-300 rtl:rotate-180" />
                            </button>

                            <!-- Right button (desktop only) -->
                            <button
                                type="button"
                                class="cursor-pointer hidden lg:flex absolute end-0 top-1/2 -translate-y-1/2 z-20 h-9 w-9 items-center justify-center rounded-full border border-zinc-200 bg-white dark:bg-zinc-900 dark:border-zinc-700 shadow-sm hover:bg-zinc-50 dark:hover:bg-zinc-800 disabled:opacity-30 disabled:cursor-not-allowed"
                                x-on:click="scrollByLogical(320)"
                                x-bind:disabled="atEnd"
                                aria-label="Scroll next"
                            >
                                <flux:icon icon="chevron-right" class="size-5 text-zinc-700 dark:text-zinc-300 rtl:rotate-180" />
                            </button>

                            <!-- Scroll container -->
                            <div
                                x-ref="scroller"
                                x-on:scroll="update()"
                                class="overflow-x-auto scrollbar-hide sm:mx-12"
                            >
                                <flux:navbar class="gap-3 !py-0 pe-4 ps-1 sm:gap-4 sm:pe-0 sm:ps-0 ltr:lg:pr-12 rtl:lg:pl-12 justify-start sm:justify-center">
                                    @foreach ($browseNavItems as $browseItem)
                                        @if ($browseItem['icon'])
                                            <flux:navbar.item
                                                wire:key="browse-nav-{{ $browseItem['key'] }}"
                                                class="border !border-accent after:!h-0 {{ $browseItem['active'] ? '!bg-accent hover:!bg-accent-hover !text-accent-foreground' : '' }}"
                                                data-nav-active="{{ $browseItem['active'] ? 'true' : 'false' }}"
                                                data-test="browse-nav-{{ $browseItem['key'] }}"
                                                href="{{ $browseItem['href'] }}"
                                                wire:navigate
                                                icon="{{ $browseItem['icon'] }}"
                                            >{{ $browseItem['label'] }}</flux:navbar.item>
                                        @else
                                            <flux:navbar.item
                                                wire:key="browse-nav-{{ $browseItem['key'] }}"
                                                class="border !border-accent after:!h-0 {{ $browseItem['active'] ? '!bg-accent hover:!bg-accent-hover !text-accent-foreground' : '' }}"
                                                data-nav-active="{{ $browseItem['active'] ? 'true' : 'false' }}"
                                                data-test="browse-nav-{{ $browseItem['key'] }}"
                                                href="{{ $browseItem['href'] }}"
                                                wire:navigate
                                            >{{ $browseItem['label'] }}</flux:navbar.item>
                                        @endif
                                    @endforeach
                                </flux:navbar>
                            </div>

                            <!-- Optional fade edges (desktop only) -->
                            <div class="pointer-events-none hidden lg:block absolute inset-y-0 start-0 w-10 ltr:bg-gradient-to-r rtl:bg-gradient-to-l from-white dark:from-zinc-900 to-transparent"></div>
                            <div class="pointer-events-none hidden lg:block absolute inset-y-0 end-[-2rem] w-10 ltr:bg-gradient-to-l rtl:bg-gradient-to-r from-white dark:from-zinc-900 to-transparent"></div>
                        </div>
                    </div>

                    <script>
                        function categoryNav() {
                            return {
                                atStart: true,
                                atEnd: false,
                                isRtl: false,
                                rtlScrollType: 'reverse',

                                init() {
                                    this.isRtl = this.getDirection() === 'rtl';
                                    this.rtlScrollType = this.isRtl ? this.getRtlScrollType() : 'ltr';
                                    this.$nextTick(() => {
                                        this.update();
                                        this.scrollToActive();
                                    });
                                    // keep buttons correct on resize
                                    window.addEventListener('resize', () => this.update());
                                },

                                getDirection() {
                                    const root = this.$root.closest('[dir]');
                                    return root?.getAttribute('dir') ?? document.documentElement.getAttribute('dir') ?? 'ltr';
                                },

                                getRtlScrollType() {
                                    const el = this.$refs.scroller;
                                    if (!el) {
                                        return 'reverse';
                                    }

                                    const initial = el.scrollLeft;
                                    el.scrollLeft = 1;
                                    const after = el.scrollLeft;
                                    el.scrollLeft = initial;

                                    if (after === 0) {
                                        return 'negative';
                                    }

                                    return initial === 0 ? 'reverse' : 'default';
                                },

                                getLogicalScroll() {
                                    const el = this.$refs.scroller;
                                    if (!el) {
                                        return 0;
                                    }

                                    const max = Math.max(el.scrollWidth - el.clientWidth, 0);

                                    if (!this.isRtl) {
                                        return Math.max(0, Math.min(el.scrollLeft, max));
                                    }

                                    const raw = el.scrollLeft;

                                    if (this.rtlScrollType === 'negative') {
                                        return Math.abs(raw);
                                    }

                                    if (this.rtlScrollType === 'default') {
                                        return max - raw;
                                    }

                                    return raw;
                                },

                                scrollByLogical(px) {
                                    if (!this.$refs.scroller) {
                                        return;
                                    }

                                    const direction = this.isRtl && this.rtlScrollType !== 'reverse' ? -1 : 1;
                                    this.$refs.scroller.scrollBy({ left: px * direction, behavior: 'smooth' });
                                    // update after scroll animation starts
                                    setTimeout(() => this.update(), 80);
                                },

                                scrollToActive() {
                                    const el = this.$refs.scroller;
                                    if (!el) {
                                        return;
                                    }

                                    const activeItem = el.querySelector('[data-nav-active="true"]');
                                    if (!activeItem) {
                                        return;
                                    }

                                    activeItem.scrollIntoView({ behavior: 'smooth', block: 'nearest', inline: 'center' });
                                    setTimeout(() => this.update(), 120);
                                },

                                update() {
                                    const el = this.$refs.scroller;
                                    if (!el) {
                                        return;
                                    }

                                    const max = Math.max(el.scrollWidth - el.clientWidth, 0);
                                    const position = this.getLogicalScroll();
                                    // small tolerance for float rounding
                                    this.atStart = position <= 2;
                                    this.atEnd = position >= (max - 2);
                                }
                            }
                        }

                    </script>
                </nav>

                </div>
            </div>
        </flux:header>
        </div>{{-- desktop-header --}}

        <div class="storefront-shell-main" data-storefront-shell="main">
            {{ $slot }}
        </div>

        <x-storefront.bottom-nav />

        <livewire:bugs.quick-report-button :key="'quick-report-frontend-'.auth()->id()" />

        <x-toaster-hub />

        <livewire:main.buy-now-modal />

        @RegisterServiceWorkerScript
        @fluxScripts
    </body>
</html>
