<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Public Registration Protection
    |--------------------------------------------------------------------------
    |
    | Limits and honeypot settings for the Fortify self-registration path.
    | Admin / salesperson user creation does not use these controls.
    |
    */

    'registration' => [
        'honeypot_field' => env('REGISTRATION_HONEYPOT_FIELD', 'website'),

        'requests_per_minute_per_ip' => (int) env('REGISTRATION_REQUESTS_PER_MINUTE_PER_IP', 30),
        'successful_per_hour_per_ip' => (int) env('REGISTRATION_SUCCESSFUL_PER_HOUR_PER_IP', 3),
        'successful_per_day_per_ip' => (int) env('REGISTRATION_SUCCESSFUL_PER_DAY_PER_IP', 10),
        'attempts_per_hour_per_email' => (int) env('REGISTRATION_ATTEMPTS_PER_HOUR_PER_EMAIL', 5),
    ],

];
