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
                'label' => 'main.profile',
                'route' => 'profile',
                'icon' => 'user',
                'active' => [
                    'profile',
                    'profile.edit-information',
                    'notifications.index',
                    'loyalty',
                    'referral-link',
                    'contact',
                ],
                'event' => 'bottom-nav-account',
                'badge' => null,
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
];
