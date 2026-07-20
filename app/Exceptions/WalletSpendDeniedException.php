<?php

declare(strict_types=1);

namespace App\Exceptions;

use App\DTOs\WalletSpendDecision;
use App\Enums\WalletSpendFailureReason;
use RuntimeException;
use Throwable;

final class WalletSpendDeniedException extends RuntimeException
{
    public function __construct(
        public readonly WalletSpendDecision $decision,
        int $code = 0,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $decision->failureReason?->userMessage() ?? 'Wallet spend denied.',
            $code,
            $previous,
        );
    }

    public function reason(): ?WalletSpendFailureReason
    {
        return $this->decision->failureReason;
    }
}
