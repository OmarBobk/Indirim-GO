<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('fulfillment:dispatch-automation')
    ->everyMinute()
    ->when(fn (): bool => (bool) config('fulfillment_automation.enabled', false));

Schedule::command('fulfillment:sweep-stale-automation-runs')
    ->everyFiveMinutes()
    ->when(fn (): bool => (bool) config('fulfillment_automation.enabled', false));

Schedule::command('loyalty:evaluate')->daily();
Schedule::command('backup:run')->daily()->at('01:00');
Schedule::command('backup:clean')->daily()->at('02:00');
Schedule::command('backup:monitor')->daily()->at('03:00');

Schedule::command('fulfillment:prune-automation-artifacts')
    ->daily()
    ->at('04:00');

Schedule::command('wasim:scan-prices')
    ->dailyAt(config('fulfillment_automation.price_scan.schedule_time', '06:00'))
    ->when(fn (): bool => (bool) config('fulfillment_automation.enabled', false)
        && (bool) config('fulfillment_automation.price_scan.enabled', true)
        && (bool) config('fulfillment_automation.price_scan.schedule_enabled', true));
