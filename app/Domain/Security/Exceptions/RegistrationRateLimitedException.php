<?php

declare(strict_types=1);

namespace App\Domain\Security\Exceptions;

use Exception;

/**
 * Thrown when a registration attempt exceeds configured rate limits.
 *
 * Callers should map this to a friendly validation error — never expose
 * which limit was exceeded or remaining attempt counts.
 */
final class RegistrationRateLimitedException extends Exception
{
    public static function make(): self
    {
        return new self('Registration rate limit exceeded.');
    }
}
