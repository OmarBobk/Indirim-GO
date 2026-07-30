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
];
