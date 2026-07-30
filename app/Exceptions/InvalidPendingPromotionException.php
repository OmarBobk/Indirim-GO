<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;
use Throwable;

final class InvalidPendingPromotionException extends RuntimeException
{
    public function __construct(
        string $message = 'Pending wallet transaction cannot be promoted.',
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
