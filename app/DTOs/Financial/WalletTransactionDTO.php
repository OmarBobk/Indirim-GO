<?php

declare(strict_types=1);

namespace App\DTOs\Financial;

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use Carbon\CarbonInterface;

/**
 * Posted customer wallet ledger row (financial facts only).
 */
final readonly class WalletTransactionDTO
{
    public function __construct(
        public string $stableKey,
        public string $publicReference,
        public WalletTransactionType $transactionType,
        public WalletTransactionDirection $direction,
        public string $status,
        public string $amount,
        public string $currency,
        public CarbonInterface $occurredAt,
        public ?string $balanceBefore,
        public ?string $balanceAfter,
        public ?string $sourceType,
        public ?string $sourceReference,
        public ?string $relatedOrderNumber,
        public ?string $customerSafeDescription,
        public ?FinancialDestinationDTO $destination,
        public bool $isCredit,
        public bool $isDebit,
    ) {}
}
