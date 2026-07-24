<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Storefront shell breakpoint
    |--------------------------------------------------------------------------
    |
    | Below this Tailwind breakpoint the mobile shell (slim top bar + bottom
    | nav) is shown. Must stay aligned with purchase sticky CTAs (lg).
    |
    */
    'shell_max' => 'lg',

    /*
    |--------------------------------------------------------------------------
    | Bottom navigation
    |--------------------------------------------------------------------------
    |
    | Configuration-driven tabs. Keys are stable analytics / test ids.
    | `active` is a list of route name patterns (supports * suffix).
    | `badge` may be: null | 'cart' (Alpine cart count).
    |
    */
    'bottom_nav' => [
        'authenticated' => [
            [
                'key' => 'home',
                'label' => 'main.home',
                'route' => 'home',
                'icon' => 'home',
                'active' => ['home', 'categories.show'],
                'event' => 'bottom-nav-home',
                'badge' => null,
                'auth' => null,
            ],
            [
                'key' => 'orders',
                'label' => 'main.my_orders',
                'route' => 'orders.index',
                'icon' => 'shopping-bag',
                'active' => ['orders.*'],
                'event' => 'bottom-nav-orders',
                'badge' => null,
                'auth' => true,
            ],
            [
                'key' => 'wallet',
                'label' => 'main.wallet',
                'route' => 'wallet',
                'icon' => 'wallet',
                'active' => ['wallet', 'wallet.topup'],
                'event' => 'bottom-nav-wallet',
                'badge' => null,
                'auth' => true,
            ],
            [
                'key' => 'cart',
                'label' => 'main.my_cart',
                'route' => 'cart',
                'icon' => 'shopping-cart',
                'active' => ['cart'],
                'event' => 'bottom-nav-cart',
                'badge' => 'cart',
                'auth' => null,
            ],
            [
                'key' => 'account',
                'label' => 'main.account',
                'route' => 'account',
                'icon' => 'user',
                'active' => [
                    'account',
                    'profile',
                    'profile.edit-information',
                    'notifications.index',
                    'loyalty',
                    'referral-link',
                    'contact',
                ],
                'event' => 'bottom-nav-account',
                'badge' => 'notifications',
                'auth' => true,
            ],
        ],
        'guest' => [
            [
                'key' => 'home',
                'label' => 'main.home',
                'route' => 'home',
                'icon' => 'home',
                'active' => ['home', 'categories.show'],
                'event' => 'bottom-nav-home',
                'badge' => null,
                'auth' => null,
            ],
            [
                'key' => 'cart',
                'label' => 'main.my_cart',
                'route' => 'cart',
                'icon' => 'shopping-cart',
                'active' => ['cart'],
                'event' => 'bottom-nav-cart',
                'badge' => 'cart',
                'auth' => null,
            ],
            [
                'key' => 'login',
                'label' => 'main.login',
                'route' => 'login',
                'icon' => 'user',
                'active' => ['login', 'register'],
                'event' => 'bottom-nav-login',
                'badge' => null,
                'auth' => false,
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Design system width tiers (M4.10.5)
    |--------------------------------------------------------------------------
    |
    | Authenticated pages must use x-storefront.page width=… (or matching
    | .storefront-page--* classes). Do not invent ad-hoc max-w-* values.
    |
    | browse     → 7xl  — home, cart, category
    | work       → 4xl  — orders, wallet, account, profile, notifications, …
    | work-wide  → 5xl / lg:6xl — order detail workspace only
    | focus      → 2xl  — wallet top-up and dense single-column forms
    |
    */
    'page_widths' => [
        'browse' => '7xl',
        'work' => '4xl',
        'work-wide' => '5xl',
        'focus' => '2xl',
    ],
];
