<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Telescope::night();

        $this->hideSensitiveRequestDetails();

        $isLocal = $this->app->environment('local');

        Telescope::filter(function (IncomingEntry $entry) use ($isLocal) {
            if ($entry->isRequest()) {
                $uri = (string) ($entry->content['uri'] ?? '');
                $path = '/'.ltrim((string) (parse_url($uri, PHP_URL_PATH) ?: $uri), '/');

                if (in_array($path, [
                    '/api/v1/auth/login',
                    '/api/v1/auth/two-factor-challenge',
                ], true)) {
                    return false;
                }
            }

            return $isLocal ||
                   $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            '_token',
            'password',
            'password_confirmation',
            'code',
            'recovery_code',
            'challenge_token',
            'requirements',
            'items.*.requirements',
            'items.0.requirements',
        ]);

        Telescope::hideRequestHeaders([
            'authorization',
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
            'idempotency-key',
            'Idempotency-Key',
        ]);

        Telescope::hideResponseParameters([
            'data.token.access_token',
            'data.challenge_token',
            'data.item.requirements_schema',
            'details.current_quote.item.requirements_schema',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', function (User $user) {
            return in_array($user->email, [
                //
            ]);
        });
    }
}
