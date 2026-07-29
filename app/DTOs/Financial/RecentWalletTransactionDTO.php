<?php

declare(strict_types=1);

namespace App\DTOs\Financial;

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use Carbon\CarbonInterface;

/**
 * Posted wallet ledger preview row. No Eloquent models.
 */
final readonly class RecentWalletTransactionDTO
{
    public function __construct(
        public string $id,
        public WalletTransactionType $type,
        public WalletTransactionDirection $direction,
        public string $amount,
        public string $currency,
        public string $status,
        public CarbonInterface $occurredAt,
        public ?string $referenceLabel,
        public ?FinancialDestinationDTO $destination,
    ) {}
}
