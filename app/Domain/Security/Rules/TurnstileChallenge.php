<?php

declare(strict_types=1);

namespace App\Domain\Security\Rules;

use App\Domain\Security\Contracts\HumanVerifier;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Validates a Cloudflare Turnstile response token server-side.
 *
 * {@see $implicit} ensures the check still runs when the field is missing
 * entirely (e.g. a bot that never renders the widget script).
 *
 * When Turnstile is disabled via config (local/testing), this rule always
 * passes so development is never blocked by a third-party dependency.
 */
final class TurnstileChallenge implements ValidationRule
{
    /**
     * Run even when the attribute is missing or empty.
     */
    public bool $implicit = true;

    public function __construct(
        private readonly ?HumanVerifier $verifier = null,
        private readonly ?string $clientIp = null,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! (bool) config('services.turnstile.enabled', true)) {
            return;
        }

        if (! is_string($value) || trim($value) === '') {
            $fail('messages.turnstile_verification_failed')->translate();

            return;
        }

        $verified = $this->verifier()->verify($value, $this->clientIp ?? request()->ip());

        if (! $verified) {
            $fail('messages.turnstile_verification_failed')->translate();
        }
    }

    private function verifier(): HumanVerifier
    {
        return $this->verifier ?? app(HumanVerifier::class);
    }
}
