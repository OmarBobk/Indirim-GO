<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

final class AccountLoginDenied extends RuntimeException
{
    private function __construct(
        public readonly string $translationKey,
        public readonly string $machineCode,
    ) {
        parent::__construct($translationKey);
    }

    public static function inactive(): self
    {
        return new self('messages.inactive', 'account_inactive');
    }

    public static function blocked(): self
    {
        return new self('messages.blocked', 'account_blocked');
    }
}
