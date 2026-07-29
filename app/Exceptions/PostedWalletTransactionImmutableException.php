<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class PostedWalletTransactionImmutableException extends RuntimeException
{
    public function __construct(
        string $message = 'Posted wallet transactions are immutable.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
