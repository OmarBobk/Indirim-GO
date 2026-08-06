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

    'progress' => [
        'heartbeat_interval_seconds' => (int) env('FULFILLMENT_AUTOMATION_PROGRESS_HEARTBEAT_SECONDS', 15),
        'emitted_at_skew_seconds' => (int) env('FULFILLMENT_AUTOMATION_PROGRESS_SKEW_SECONDS', 300),
        'max_payload_bytes' => (int) env('FULFILLMENT_AUTOMATION_PROGRESS_MAX_BYTES', 8192),
        'events_per_run_limit' => (int) env('FULFILLMENT_AUTOMATION_PROGRESS_EVENTS_LIMIT', 100),
        'worker_health_cache_seconds' => (int) env('FULFILLMENT_AUTOMATION_WORKER_HEALTH_CACHE', 15),
    ],

    'liveness' => [
        'purchase_slow_seconds' => (int) env('FULFILLMENT_AUTOMATION_PURCHASE_SLOW_SECONDS', 180),
        'purchase_stale_seconds' => (int) env('FULFILLMENT_AUTOMATION_PURCHASE_STALE_SECONDS', 480),
        'reconcile_slow_seconds' => (int) env('FULFILLMENT_AUTOMATION_RECONCILE_SLOW_SECONDS', 180),
        'reconcile_stale_seconds' => (int) env('FULFILLMENT_AUTOMATION_RECONCILE_STALE_SECONDS', 480),
        'legacy_fallback_stale_minutes' => (int) env('FULFILLMENT_AUTOMATION_LEGACY_STALE_MINUTES', 30),
        'scheduled_reconcile_grace_seconds' => (int) env('FULFILLMENT_AUTOMATION_RECONCILE_SCHEDULE_GRACE', 600),
    ],

    'artifacts' => [
        'retention_days' => (int) env('FULFILLMENT_AUTOMATION_ARTIFACT_RETENTION_DAYS', 30),
    ],

    'reconcile' => [
        'initial_delay_seconds' => (int) env('FULFILLMENT_AUTOMATION_RECONCILE_INITIAL_DELAY', 60),
        'max_attempts' => (int) env('FULFILLMENT_AUTOMATION_RECONCILE_MAX_ATTEMPTS', 48),
        'delays_seconds' => [60, 120, 300, 600, 900, 1800, 3600],
    ],

    'price_scan' => [
        'enabled' => (bool) env('FULFILLMENT_AUTOMATION_PRICE_SCAN_ENABLED', true),
        'custom_reference_quantity' => (int) env('FULFILLMENT_AUTOMATION_PRICE_SCAN_CUSTOM_QTY', 1000),
        'delay_ms_between_products' => (int) env('FULFILLMENT_AUTOMATION_PRICE_SCAN_DELAY_MS', 400),
        'run_timeout_seconds' => (int) env('FULFILLMENT_AUTOMATION_PRICE_SCAN_TIMEOUT', 3600),
        'drift_tolerance' => (float) env('FULFILLMENT_AUTOMATION_PRICE_SCAN_DRIFT_TOLERANCE', 0.0001),
        'schedule_enabled' => (bool) env('FULFILLMENT_AUTOMATION_PRICE_SCAN_SCHEDULE_ENABLED', true),
        'schedule_time' => env('FULFILLMENT_AUTOMATION_PRICE_SCAN_SCHEDULE_TIME', '06:00'),
        'notify_on_drift' => (bool) env('FULFILLMENT_AUTOMATION_PRICE_SCAN_NOTIFY_ON_DRIFT', true),
        'notify_on_reactive_flag' => (bool) env('FULFILLMENT_AUTOMATION_PRICE_SCAN_NOTIFY_ON_REACTIVE_FLAG', true),
        'notify_permission' => env('FULFILLMENT_AUTOMATION_PRICE_SCAN_NOTIFY_PERMISSION', 'update_product_prices'),
        'reactive_flags_enabled' => (bool) env('FULFILLMENT_AUTOMATION_PRICE_SCAN_REACTIVE_FLAGS', true),
    ],

    'wasim_probe' => [
        'enabled' => (bool) env('FULFILLMENT_AUTOMATION_WASIM_PROBE_ENABLED', true),
        'schedule_minutes' => (int) env('FULFILLMENT_AUTOMATION_WASIM_PROBE_SCHEDULE_MINUTES', 20),
        'timeout_seconds' => (int) env('FULFILLMENT_AUTOMATION_WASIM_PROBE_TIMEOUT_SECONDS', 90),
        'cache_seconds' => (int) env('FULFILLMENT_AUTOMATION_WASIM_PROBE_CACHE_SECONDS', 60),
        'test_product_api' => env('FULFILLMENT_AUTOMATION_WASIM_PROBE_PRODUCT_API'),
        'expected_product_id' => env('FULFILLMENT_AUTOMATION_WASIM_PROBE_EXPECTED_PRODUCT_ID'),
        'expected_currency' => env('FULFILLMENT_AUTOMATION_WASIM_PROBE_EXPECTED_CURRENCY', 'TRY'),
    ],

    'circuits' => [
        'purchase' => [
            'threshold_count' => (int) env('FULFILLMENT_AUTOMATION_CIRCUIT_PURCHASE_THRESHOLD', 3),
            'threshold_window_minutes' => (int) env('FULFILLMENT_AUTOMATION_CIRCUIT_PURCHASE_WINDOW', 10),
        ],
        'reconcile' => [
            'threshold_count' => (int) env('FULFILLMENT_AUTOMATION_CIRCUIT_RECONCILE_THRESHOLD', 3),
            'threshold_window_minutes' => (int) env('FULFILLMENT_AUTOMATION_CIRCUIT_RECONCILE_WINDOW', 15),
        ],
        'price_scan' => [
            'threshold_count' => (int) env('FULFILLMENT_AUTOMATION_CIRCUIT_PRICE_SCAN_THRESHOLD', 3),
            'threshold_window_minutes' => (int) env('FULFILLMENT_AUTOMATION_CIRCUIT_PRICE_SCAN_WINDOW', 15),
        ],
        'probe_freshness_seconds' => (int) env('FULFILLMENT_AUTOMATION_CIRCUIT_PROBE_FRESHNESS', 1800),
        'supported_ui_versions' => ['wasim-ui-v1'],
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
