@php
    $pendingRefundsCount = 0;
    $dashboardHref = auth()->user()?->can('view_dashboard')
        ? route('dashboard')
        : (auth()->user()?->can('view_referrals') ? route('salesperson.dashboard') : route('home'));
    if (auth()->check() && auth()->user()?->can('view_refunds')) {
        $pendingRefundsCount = \App\Models\WalletTransaction::query()
            ->where('type', \App\Enums\WalletTransactionType::Refund)
            ->where('status', \App\Models\WalletTransaction::STATUS_PENDING)
            ->count();
    }

@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-white dark:bg-zinc-800 {{ app()->getLocale() === 'ar' ? 'rtl' : 'ltr' }}">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-zinc-200 bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ $dashboardHref }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            <x-admin.usd-try-rate-panel variant="sidebar" />

            <flux:sidebar.nav>
                @if (auth()->user()?->can('view_dashboard') || auth()->user()?->can('view_referrals'))
                    <flux:sidebar.group :heading="__('messages.nav_overview')" class="grid">
                        @can('view_dashboard')
                            <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                                {{ __('messages.dashboard') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('view_referrals')
                            <flux:sidebar.item icon="chart-bar" :href="route('salesperson.dashboard')" :current="request()->routeIs('salesperson.dashboard')" wire:navigate>
                                {{ __('messages.salesperson_dashboard') }}
                            </flux:sidebar.item>
                        @endcan
                        @can('manage_referred_users')
                            <flux:sidebar.item icon="users" :href="route('salesperson.users.index')" :current="request()->routeIs('salesperson.users.*')" wire:navigate>
                                {{ __('messages.nav_my_customers') }}
                            </flux:sidebar.item>
                        @endcan
                    </flux:sidebar.group>
                @endif

                @if (auth()->user()?->can('manage_products') || auth()->user()?->can('manage_sections') || auth()->user()?->can('manage_loyalty_tiers') || auth()->user()?->can('update_product_prices'))
                <flux:sidebar.group expandable :expanded="(request()->routeIs('categories')) or (request()->routeIs('packages')) or (request()->routeIs('products')) or (request()->routeIs('product-entry-prices')) or (request()->routeIs('pricing-rules')) or (request()->routeIs('loyalty-tiers'))" :heading="__('messages.nav_content_management')" class="grid">
                    @can('manage_sections')
                    <flux:sidebar.item icon="tag" :href="route('categories')" :current="(request()->routeIs('categories'))" wire:navigate>
                        {{ __('messages.categories') }}
                    </flux:sidebar.item>
                    @endcan
                    @can('manage_products')
                    <flux:sidebar.item icon="cube" :href="route('packages')" :current="(request()->routeIs('packages'))" wire:navigate>
                        {{ __('messages.packages') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="shopping-cart" :href="route('products')" :current="(request()->routeIs('products'))" wire:navigate>
                        {{ __('messages.products') }}
                    </flux:sidebar.item>
                    @can('update_product_prices')
                    <flux:sidebar.item icon="pencil-square" :href="route('product-entry-prices')" :current="request()->routeIs('product-entry-prices')" wire:navigate>
                        {{ __('messages.product_entry_prices') }}
                    </flux:sidebar.item>
                    @endcan
                    <flux:sidebar.item icon="currency-dollar" :href="route('pricing-rules')" :current="(request()->routeIs('pricing-rules'))" wire:navigate>
                        {{ __('messages.pricing_rules') }}
                    </flux:sidebar.item>
                    @endcan
                    @can('manage_loyalty_tiers')
                    <flux:sidebar.item icon="sparkles" :href="route('loyalty-tiers')" :current="(request()->routeIs('loyalty-tiers'))" wire:navigate>
                        {{ __('messages.loyalty_tiers') }}
                    </flux:sidebar.item>
                    @endcan
                </flux:sidebar.group>
                @endif

                <livewire:sidebar.operations-group
                    :expanded="(request()->routeIs('fulfillments')) || (request()->routeIs('admin.orders.*')) || (request()->routeIs('admin.notifications.index'))"
                    :heading="__('messages.nav_operations')"
                    :key="'sidebar-operations-group'"
                >
                    <flux:sidebar.item icon="bell" :href="route('admin.notifications.index')" :current="request()->routeIs('admin.notifications.index')" wire:navigate>
                        <span class="flex items-center gap-2">
                            {{ __('messages.notifications') }}
                            <livewire:sidebar.notification-indicator :key="'sidebar-notification-indicator'" />
                        </span>
                    </flux:sidebar.item>
                    @can('view_fulfillments')
                    <flux:sidebar.item icon="list-bullet" :href="route('fulfillments')" :current="request()->routeIs('fulfillments')" wire:navigate>
                        <span class="flex items-center gap-2">
                            {{ __('messages.fulfillments') }}
                            <livewire:sidebar.fulfillment-indicator :key="'sidebar-fulfillment-indicator'" />
                        </span>
                    </flux:sidebar.item>
                    @endcan
                    @can('view_orders')
                    <flux:sidebar.item icon="shopping-bag" :href="route('admin.orders.index')" :current="request()->routeIs('admin.orders.*')" wire:navigate>
                        {{ __('messages.orders') }}
                    </flux:sidebar.item>
                    @endcan
                    @can('view_refunds')
                    <flux:sidebar.item icon="receipt-refund" :href="route('refunds')" :current="request()->routeIs('refunds')" wire:navigate>
                        <span class="flex items-center gap-2">
                            {{ __('messages.refund_requests') }}
                            @if ($pendingRefundsCount > 0)
                                <span class="size-2 shrink-0 rounded-full bg-red-500" aria-hidden="true"></span>
                            @endif
                        </span>
                    </flux:sidebar.item>
                    @endcan
                </livewire:sidebar.operations-group>

                @if (auth()->user()?->can('manage_topups') || auth()->user()?->can('manage_settlements'))
                    <livewire:sidebar.financials-group
                        :expanded="request()->routeIs('topups') || request()->routeIs('settlements') || request()->routeIs('customer-funds') || request()->routeIs('admin.commissions') || request()->routeIs('admin.payout-requests')"
                        :heading="__('messages.nav_financials')"
                        :key="'sidebar-financials-group'"
                    >
                        @can('manage_topups')
                        <flux:sidebar.item icon="wallet" :href="route('topups')" :current="request()->routeIs('topups')" wire:navigate>
                            <span class="flex items-center gap-2">
                                {{ __('messages.topups') }}
                                <livewire:sidebar.topup-indicator :key="'sidebar-topup-indicator'" />
                            </span>
                        </flux:sidebar.item>
                        @endcan
                        @can('manage_topups')
                        <flux:sidebar.item icon="banknotes" :href="route('customer-funds')" :current="request()->routeIs('customer-funds')" wire:navigate>
                            {{ __('messages.customer_funds') }}
                        </flux:sidebar.item>
                        @endcan
                        @can('manage_settlements')
                        <flux:sidebar.item icon="currency-dollar" :href="route('settlements')" :current="request()->routeIs('settlements')" wire:navigate>
                            {{ __('messages.settlements') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="document-text" :href="route('admin.commissions')" :current="request()->routeIs('admin.commissions')" wire:navigate>
                            {{ __('messages.commissions') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="inbox-arrow-down" :href="route('admin.payout-requests')" :current="request()->routeIs('admin.payout-requests')" wire:navigate>
                            {{ __('messages.payout_requests') }}
                        </flux:sidebar.item>
                        @endcan
                    </livewire:sidebar.financials-group>
                @endif

                <flux:sidebar.group :heading="__('messages.nav_audit_monitoring')" class="grid">
                    @can('view_activities')
                    <flux:sidebar.item icon="clock" :href="route('admin.activities.index')" :current="request()->routeIs('admin.activities.*')" wire:navigate>
                        {{ __('messages.activities') }}
                    </flux:sidebar.item>
                    <flux:sidebar.item icon="list-bullet" :href="route('admin.system-events.index')" :current="request()->routeIs('admin.system-events.*')" wire:navigate>
                        {{ __('messages.system_events') }}
                    </flux:sidebar.item>
                    @endcan
                    @can('manage_users')
                    <flux:sidebar.item icon="users" :href="route('admin.users.index')" :current="request()->routeIs('admin.users.index')" wire:navigate>
                        {{ __('messages.users') }}
                    </flux:sidebar.item>
                    @if ((request()->routeIs('admin.users.show') || request()->routeIs('admin.users.audit')) && request()->route('user'))
                    <flux:sidebar.item icon="clock" :href="route('admin.users.audit', request()->route('user'))" :current="request()->routeIs('admin.users.audit')" wire:navigate>
                        {{ __('messages.audit_timeline') }}
                    </flux:sidebar.item>
                    @endif
                    @endcan
                </flux:sidebar.group>

                <flux:sidebar.group :heading="__('messages.nav_website_settings')" class="grid">
                    @can('manage_bugs')
                        <flux:sidebar.item icon="bug-ant" :href="route('admin.bugs.index')" :current="request()->routeIs('admin.bugs.*')" wire:navigate>
                            <span class="flex items-center gap-2">
                                {{ __('Bug Reports') }}
                                <livewire:sidebar.bug-reports-indicator :key="'sidebar-bug-reports-indicator'" />
                            </span>
                        </flux:sidebar.item>
                    @endcan
                    @role('admin')
                    <flux:sidebar.item icon="globe-alt" :href="route('admin.website-settings')" :current="request()->routeIs('admin.website-settings')" wire:navigate>
                        {{ __('messages.website_settings') }}
                    </flux:sidebar.item>
                    @endrole
                </flux:sidebar.group>
            </flux:sidebar.nav>

            <flux:spacer />

            <flux:sidebar.nav>
                <flux:sidebar.item icon="folder-git-2" href="https://github.com/laravel/livewire-starter-kit" target="_blank">
                    {{ __('messages.repository') }}
                </flux:sidebar.item>

                <flux:sidebar.item icon="book-open-text" href="{{route('home')}}" target="_blank">
                    {{ __('messages.homepage') }}
                </flux:sidebar.item>
            </flux:sidebar.nav>



            <flux:sidebar.nav>
                <livewire:language-switcher />
                <flux:button x-data x-on:click="$flux.dark = ! $flux.dark" icon="moon" variant="subtle" aria-label="Toggle dark mode" />
            </flux:sidebar.nav>

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <livewire:sidebar.sidebar-toggle-badge :key="'sidebar-toggle-badge'" />

            <flux:spacer />


            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('messages.settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('messages.log_out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>


        </flux:header>

        {{ $slot }}

        <livewire:bugs.quick-report-button :key="'quick-report-backend-'.auth()->id()" />

        <x-toaster-hub />

        @RegisterServiceWorkerScript
        @fluxScripts
    </body>
</html>
