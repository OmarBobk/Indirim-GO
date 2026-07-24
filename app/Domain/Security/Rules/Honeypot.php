<?php

declare(strict_types=1);

namespace App\Domain\Security\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Rejects submissions where a honeypot field was filled.
 *
 * Legitimate browsers leave the field empty (or omit it). Bots that auto-fill
 * forms trip this rule. The failure message is intentionally generic so bots
 * cannot fingerprint the honeypot.
 */
final class Honeypot implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (is_string($value) && trim($value) === '') {
            return;
        }

        $fail('messages.registration_unavailable')->translate();
    }
}
