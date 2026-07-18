<?php

declare(strict_types=1);

namespace App\Domain\Security\Listeners;

use App\Domain\Security\Services\RegistrationRateLimiter;
use Illuminate\Auth\Events\Registered;

/**
 * Records a successful public registration against IP success budgets.
 *
 * Fired by Fortify after CreateNewUser returns. Admin/salesperson user
 * creation does not dispatch Registered, so those paths stay unaffected.
 */
final class RecordSuccessfulRegistration
{
    public function __construct(
        private readonly RegistrationRateLimiter $rateLimiter,
    ) {}

    public function handle(Registered $event): void
    {
        $ip = request()->ip();

        if (! is_string($ip) || $ip === '') {
            return;
        }

        $this->rateLimiter->recordSuccess($ip);
    }
}
