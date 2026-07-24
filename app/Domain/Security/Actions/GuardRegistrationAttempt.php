<?php

declare(strict_types=1);

namespace App\Domain\Security\Actions;

use App\Domain\Security\Exceptions\RegistrationRateLimitedException;
use App\Domain\Security\Rules\Honeypot;
use App\Domain\Security\Rules\TurnstileChallenge;
use App\Domain\Security\Services\RegistrationRateLimiter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Registration-only security gate for the public Fortify signup path.
 *
 * Runs honeypot → rate-limit allowance → Turnstile, then records an attempt.
 * Does not validate profile fields or create users — that remains CreateNewUser.
 *
 * Not reused for login or phone verification; those flows get their own thin
 * gates. Shared building blocks live under Rules/ and Services/.
 */
final class GuardRegistrationAttempt
{
    public function __construct(
        private readonly RegistrationRateLimiter $rateLimiter,
    ) {}

    /**
     * @throws ValidationException
     */
    public function __invoke(Request $request): void
    {
        $ip = (string) $request->ip();
        $email = $request->input('email');
        $email = is_string($email) ? $email : null;
        $honeypotField = (string) config('security.registration.honeypot_field', 'website');

        try {
            $this->rateLimiter->ensureAllowed($ip, $email);
        } catch (RegistrationRateLimitedException) {
            throw ValidationException::withMessages([
                'email' => [__('messages.registration_unavailable')],
            ]);
        }

        // Count this submission before Turnstile so failed challenges still consume budget.
        $this->rateLimiter->hitAttempt($ip, $email);

        Validator::make($request->all(), [
            $honeypotField => [new Honeypot],
            'cf-turnstile-response' => [new TurnstileChallenge(clientIp: $ip)],
        ])->validate();
    }
}
