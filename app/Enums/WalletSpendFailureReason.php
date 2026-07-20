<?php

declare(strict_types=1);

namespace App\Enums;

enum WalletSpendFailureReason: string
{
    case InsufficientFunds = 'insufficient_funds';
    case InvalidAmount = 'invalid_amount';

    /**
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function userMessage(): string
    {
        return match ($this) {
            self::InsufficientFunds => 'Insufficient wallet balance.',
            self::InvalidAmount => 'Wallet spend amount must be greater than zero.',
        };
    }
}
