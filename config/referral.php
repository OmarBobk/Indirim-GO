<?php

declare(strict_types=1);

return [
    'cookie_name' => env('REFERRAL_COOKIE_NAME', 'karman_ref'),
    /** Last-click attribution cookie lifetime (minutes). */
    'cookie_ttl_minutes' => (int) env('REFERRAL_COOKIE_TTL_MINUTES', 30 * 24 * 60),
    /** Env fallback when website settings row has no stored default. */
    'default_commission_rate_percent' => (string) env('REFERRAL_DEFAULT_COMMISSION_RATE_PERCENT', '20.00'),
];
