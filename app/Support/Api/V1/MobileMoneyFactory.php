<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Models\User;
use App\Support\FrontendMoney;
use App\Support\LedgerMoney;

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
     * Preferred path: decimal string → LedgerMoney normalize → Money envelope.
     *
     * @return MoneyArray
     */
    public function fromUsdDecimal(string $amount): array
    {
        $normalized = LedgerMoney::normalize($amount);
        $display = $this->money->displayForUsdAmount($normalized, 2);

        return [
            'amount' => $normalized,
            'currency' => 'USD',
            'display' => $display,
        ];
    }

    /**
     * Compatibility wrapper for catalog callers that still pass numeric USD totals.
     * Prefer {@see fromUsdDecimal()} for purchase quote/receipt paths.
     *
     * @return MoneyArray|null
     */
    public function fromUsdAmount(float|int|string|null $amount): ?array
    {
        if ($amount === null) {
            return null;
        }

        if (is_string($amount)) {
            return $this->fromUsdDecimal($amount);
        }

        return $this->fromUsdDecimal(sprintf('%.2F', $amount));
    }
}
