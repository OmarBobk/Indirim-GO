<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class InsufficientWalletBalanceException extends RuntimeException
{
    public function __construct(
        string $message = 'Insufficient wallet balance for ledger debit.',
        int $code = 0,
        ?Throwable $previous = null,
        public readonly ?string $balanceAfter = null,
        public readonly ?string $minimumAllowedBalance = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
