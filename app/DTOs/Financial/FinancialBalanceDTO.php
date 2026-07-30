<?php

declare(strict_types=1);

namespace App\DTOs\Financial;

/**
 * Authoritative customer wallet balance snapshot (decimal strings, scale 2).
 */
final readonly class FinancialBalanceDTO
{
    public function __construct(
        public string $availableToSpend,
        public string $prepaidBalance,
        public string $outstandingDebt,
        public string $currency,
        public bool $creditFacilityActive,
        public ?string $creditLimit,
        public ?string $remainingCredit,
        public bool $hasOutstandingDebt,
    ) {}
}
