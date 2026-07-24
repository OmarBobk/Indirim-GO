<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class IdempotencyConflictException extends RuntimeException
{
    public function __construct(
        string $message = 'Idempotency key already used for a different wallet operation.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
