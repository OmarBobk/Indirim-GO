@php
    use App\Support\StorefrontShell;

    $unreadCount = auth()->check() ? StorefrontShell::unreadNotificationCount() : 0;
    $notificationsAria = $unreadCount > 0
        ? __('main.notifications_unread_aria', ['count' => $unreadCount > 9 ? '9+' : $unreadCount])
        : __('messages.notifications');
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

        <div class="ms-auto flex shrink-0 items-center gap-0.5">
            @auth
                <a
                    href="{{ route('notifications.index') }}"
                    wire:navigate
                    class="storefront-shell-icon-btn relative inline-flex size-11 items-center justify-center rounded-full text-zinc-700 transition hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:text-zinc-200 dark:hover:bg-zinc-800 dark:focus-visible:ring-offset-zinc-900"
                    aria-label="{{ $notificationsAria }}"
                    data-test="mobile-notifications"
                    data-event="top-nav-notifications"
                >
                    <flux:icon icon="bell" class="size-5" />
                    @if ($unreadCount > 0)
                        <span
                            class="storefront-unread-badge absolute end-1.5 top-1.5"
                            data-test="mobile-notifications-badge"
                            aria-hidden="true"
                        >
                            {{ $unreadCount > 9 ? '9+' : $unreadCount }}
                        </span>
                    @endif
                </a>
            @endauth

            {{-- On authenticated home, Command Zone owns search (no duplicate toggle). --}}
            @unless (auth()->check() && request()->routeIs('home'))
                <button
                    type="button"
                    class="storefront-shell-icon-btn inline-flex size-11 items-center justify-center rounded-full text-zinc-700 transition hover:bg-zinc-100 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 focus-visible:ring-offset-2 focus-visible:ring-offset-white dark:text-zinc-200 dark:hover:bg-zinc-800 dark:focus-visible:ring-offset-zinc-900"
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
            @endunless
        </div>
    </div>

    @unless (auth()->check() && request()->routeIs('home'))
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
    @endunless
</header>
