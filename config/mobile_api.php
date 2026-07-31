<?php

declare(strict_types=1);

return [
    'token' => [
        'ability' => 'mobile:access',
        'lifetime_days' => 30,
        'device_name_max_length' => 80,
    ],

    'two_factor_challenge' => [
        'cache_prefix' => 'mobile-api:two-factor:',
        'lifetime_minutes' => 5,
        'max_attempts' => 5,
        'lock_seconds' => 10,
        'lock_wait_seconds' => 2,
    ],

    'checkout' => [
        /** Quote optimistic-concurrency fingerprint version. */
        'quote_version' => 1,
        /** Seconds until a quote fingerprint is considered expired. */
        'quote_ttl_seconds' => 300,
        /**
         * Completed/failed mobile checkout attempts are retained for recovery.
         * Rows older than this window are pruned by mobile-checkout:prune-attempts.
         * Retries after retention are no longer guaranteed to replay.
         */
        'idempotency_retention_hours' => 72,
        /**
         * Stale processing rows with no committed order/debit may be reclaimed
         * after this many seconds under the bounded orphan policy.
         */
        'processing_stale_seconds' => 60,
        'idempotency_header' => 'Idempotency-Key',
        'idempotency_key_max_length' => 128,
    ],
];
