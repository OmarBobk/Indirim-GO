<?php

declare(strict_types=1);

return [

    'enabled' => (bool) env('FULFILLMENT_AUTOMATION_ENABLED', false),

    'worker_url' => env('FULFILLMENT_AUTOMATION_WORKER_URL', 'http://127.0.0.1:3100'),

    'callback_secret' => env('FULFILLMENT_AUTOMATION_CALLBACK_SECRET', ''),

    'dispatch' => [
        'batch_size' => (int) env('FULFILLMENT_AUTOMATION_DISPATCH_BATCH', 10),
        'max_attempts' => (int) env('FULFILLMENT_AUTOMATION_MAX_ATTEMPTS', 3),
        'revert_to_queued_on_dispatch_failure' => (bool) env('FULFILLMENT_AUTOMATION_REVERT_ON_DISPATCH_FAIL', true),
    ],

    'timeouts' => [
        'dispatch_seconds' => (int) env('FULFILLMENT_AUTOMATION_DISPATCH_TIMEOUT', 15),
        'run_seconds' => (int) env('FULFILLMENT_AUTOMATION_RUN_TIMEOUT', 300),
        'stale_sweep_minutes' => (int) env('FULFILLMENT_AUTOMATION_STALE_MINUTES', 30),
        'signature_skew_seconds' => (int) env('FULFILLMENT_AUTOMATION_SIGNATURE_SKEW', 300),
    ],

    'artifacts' => [
        'retention_days' => (int) env('FULFILLMENT_AUTOMATION_ARTIFACT_RETENTION_DAYS', 30),
    ],

    'reconcile' => [
        'initial_delay_seconds' => (int) env('FULFILLMENT_AUTOMATION_RECONCILE_INITIAL_DELAY', 60),
        'max_attempts' => (int) env('FULFILLMENT_AUTOMATION_RECONCILE_MAX_ATTEMPTS', 48),
        'delays_seconds' => [60, 120, 300, 600, 900, 1800, 3600],
    ],

    'queue' => env('FULFILLMENT_AUTOMATION_QUEUE', 'fulfillment-automation'),

    'suppliers' => [
        'wasim' => [
            'driver' => 'wasim',
            'session_key' => 'wasim-main',
            'max_concurrent_runs' => 1,
            'credentials' => [
                'username' => env('FULFILLMENT_AUTOMATION_WASIM_USERNAME'),
                'password' => env('FULFILLMENT_AUTOMATION_WASIM_PASSWORD'),
            ],
        ],
        'acme' => [
            'driver' => 'acme',
            'session_key' => 'acme-main',
            'max_concurrent_runs' => 1,
            'credentials' => [
                'username' => env('FULFILLMENT_AUTOMATION_ACME_USERNAME'),
                'password' => env('FULFILLMENT_AUTOMATION_ACME_PASSWORD'),
            ],
        ],
    ],

];
