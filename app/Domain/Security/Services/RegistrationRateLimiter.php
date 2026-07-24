<?php

declare(strict_types=1);

namespace App\Domain\Security\Services;

use App\Domain\Security\Exceptions\RegistrationRateLimitedException;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Multi-layer rate limiting for public self-registration.
 *
 * Attempt limits (IP/minute, email/hour) are checked and incremented on every
 * guarded submission. Success limits (IP/hour, IP/day) are checked before
 * create and incremented only after a user is actually persisted.
 */
final class RegistrationRateLimiter
{
    /**
     * Ensure this IP / email may still attempt registration.
     *
     * @throws RegistrationRateLimitedException
     */
    public function ensureAllowed(string $ip, ?string $email = null): void
    {
        if ($this->tooManyAttempts($this->requestsPerMinuteKey($ip), $this->requestsPerMinuteMax())) {
            throw RegistrationRateLimitedException::make();
        }

        if ($this->tooManyAttempts($this->successfulPerHourKey($ip), $this->successfulPerHourMax())) {
            throw RegistrationRateLimitedException::make();
        }

        if ($this->tooManyAttempts($this->successfulPerDayKey($ip), $this->successfulPerDayMax())) {
            throw RegistrationRateLimitedException::make();
        }

        $normalizedEmail = $this->normalizeEmail($email);

        if ($normalizedEmail !== null && $this->tooManyAttempts(
            $this->attemptsPerHourEmailKey($normalizedEmail),
            $this->attemptsPerHourEmailMax()
        )) {
            throw RegistrationRateLimitedException::make();
        }
    }

    /**
     * Record a registration attempt (success or failure after the security gate).
     */
    public function hitAttempt(string $ip, ?string $email = null): void
    {
        RateLimiter::hit($this->requestsPerMinuteKey($ip), 60);

        $normalizedEmail = $this->normalizeEmail($email);

        if ($normalizedEmail !== null) {
            RateLimiter::hit($this->attemptsPerHourEmailKey($normalizedEmail), 3600);
        }
    }

    /**
     * Record a successful registration against IP success budgets.
     */
    public function recordSuccess(string $ip): void
    {
        RateLimiter::hit($this->successfulPerHourKey($ip), 3600);
        RateLimiter::hit($this->successfulPerDayKey($ip), 86400);
    }

    private function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        return RateLimiter::tooManyAttempts($key, $maxAttempts);
    }

    private function normalizeEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $normalized = Str::lower(trim($email));

        return $normalized === '' ? null : $normalized;
    }

    private function requestsPerMinuteKey(string $ip): string
    {
        return 'registration:requests:minute:'.$ip;
    }

    private function successfulPerHourKey(string $ip): string
    {
        return 'registration:success:hour:'.$ip;
    }

    private function successfulPerDayKey(string $ip): string
    {
        return 'registration:success:day:'.$ip;
    }

    private function attemptsPerHourEmailKey(string $email): string
    {
        return 'registration:attempts:hour:email:'.sha1($email);
    }

    private function requestsPerMinuteMax(): int
    {
        return max(1, (int) config('security.registration.requests_per_minute_per_ip', 30));
    }

    private function successfulPerHourMax(): int
    {
        return max(1, (int) config('security.registration.successful_per_hour_per_ip', 3));
    }

    private function successfulPerDayMax(): int
    {
        return max(1, (int) config('security.registration.successful_per_day_per_ip', 10));
    }

    private function attemptsPerHourEmailMax(): int
    {
        return max(1, (int) config('security.registration.attempts_per_hour_per_email', 5));
    }
}
