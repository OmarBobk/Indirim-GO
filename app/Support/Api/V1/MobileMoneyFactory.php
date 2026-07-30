<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Models\User;
use App\Support\FrontendMoney;

/**
 * Builds the mobile Money envelope from an authoritative USD ledger amount.
 *
 * @phpstan-type MoneyArray array{amount: string, currency: string, display: array{currency: string, formatted: string}}
 */
final class MobileMoneyFactory
{
    public function __construct(
        private readonly FrontendMoney $money,
    ) {}

    public static function forUser(User $user): self
    {
        return new self(FrontendMoney::for($user));
    }

    /**
     * @return MoneyArray|null
     */
    public function fromUsdAmount(?float $amount): ?array
    {
        if ($amount === null) {
            return null;
        }

        $normalized = round($amount, 2, PHP_ROUND_HALF_EVEN);
        $display = $this->money->displayForUsdAmount($normalized, 2);

        return [
            'amount' => number_format($normalized, 2, '.', ''),
            'currency' => 'USD',
            'display' => $display,
        ];
    }
}
