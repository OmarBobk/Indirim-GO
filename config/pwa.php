<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Would you like the install button to appear on all pages?
      Set true/false
    |--------------------------------------------------------------------------
    */

    'install-button' => true,

    /*
    |--------------------------------------------------------------------------
    | PWA Manifest Configuration
    |--------------------------------------------------------------------------
    | Values are written to the web app manifest (e.g. public/site.webmanifest).
    | Run: php artisan erag:update-manifest
    |
    | display: standalone | fullscreen | minimal-ui | browser
    | Icons: paths relative to public/; include 192 and 512 for install prompts.
    */

    'manifest' => [
        'name' => config('app.name'),
        'short_name' => config('app.name'),
        'description' => 'E-commerce and wallet platform.',
        'theme_color' => '#eab308',
        'background_color' => '#fefce8',
        'display' => 'standalone',
        'orientation' => 'portrait',
        'scope' => '/',
        'start_url' => '/',
        'icons' => [
            [
                'src' => 'logo_sm.png',
                'sizes' => '192x192',
                'type' => 'image/png',
            ],
            [
                'src' => 'logo _lg.png',
                'sizes' => '512x512',
                'type' => 'image/png',
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Debug Configuration
    |--------------------------------------------------------------------------
    | Toggles the application's debug mode based on the environment variable
    */

    'debug' => env('APP_DEBUG', false),

    /*
    |--------------------------------------------------------------------------
    | Livewire Integration
    |--------------------------------------------------------------------------
    | Set to true if you're using Livewire in your application to enable
    | Livewire-specific PWA optimizations or features.
    */

    'livewire-app' => true,
];
