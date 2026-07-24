<?php

declare(strict_types=1);

namespace App\Enums;

use App\DTOs\WalletSpendDecision;

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

    public function userMessage(?WalletSpendDecision $decision = null): string
    {
        return match ($this) {
            self::InsufficientFunds => __('messages.wallet_spend_insufficient', [
                'available' => $decision?->availableToSpend ?? '0.00',
                'currency' => config('billing.currency', 'USD'),
            ]),
            self::InvalidAmount => __('messages.wallet_spend_invalid_amount'),
        };
    }
}
