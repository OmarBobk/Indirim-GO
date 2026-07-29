<?php

declare(strict_types=1);

namespace App\DTOs\Financial;

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use Carbon\CarbonInterface;

/**
 * Owned posted customer transaction detail (M6.5).
 *
 * @param  list<array{key: string, label_key: string, occurred_at: string|null}>  $timeline
 */
final readonly class CustomerTransactionDetailDTO
{
    /**
     * @param  list<array{key: string, label_key: string, occurred_at: string|null}>  $timeline
     */
    public function __construct(
        public string $stableKey,
        public string $publicReference,
        public WalletTransactionType $transactionType,
        public WalletTransactionDirection $direction,
        public string $status,
        public string $amount,
        public string $currency,
        public CarbonInterface $postedAt,
        public ?string $balanceBefore,
        public ?string $balanceAfter,
        public bool $moneyIn,
        public bool $hasBalanceSnapshots,
        public bool $isIntegrityAnomaly,
        public ?string $sourceTitle,
        public ?string $sourceDescription,
        public ?string $relatedOrderNumber,
        public ?string $relatedTopupPublicRef,
        public ?string $relatedRefundPublicRef,
        public ?string $paymentMethodName,
        public ?string $productLabel,
        public ?string $customerSafeReason,
        public array $timeline,
        public ?FinancialDestinationDTO $sourceDestination,
        public FinancialDestinationDTO $listDestination,
        public int $receiptVersion,
    ) {}
}
