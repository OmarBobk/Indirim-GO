<?php

declare(strict_types=1);

namespace App\Exceptions;

use InvalidArgumentException;
use Throwable;

final class InvalidWalletPostingAmountException extends InvalidArgumentException
{
    public function __construct(
        string $message = 'Invalid wallet posting amount.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
