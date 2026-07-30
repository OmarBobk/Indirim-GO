<?php

declare(strict_types=1);

namespace App\DTOs\Earnings;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\Enums\PayoutRequestStatus;

/**
 * @param  list<CommissionDTO>  $items
 * @param  list<array{credited_at: string, amount: string, wallet_transaction_public_ref: ?string, destination: ?FinancialDestinationDTO}>  $recentCredits
 */
final readonly class SalespersonEarningsPageDTO
{
    /**
     * @param  list<CommissionDTO>  $items
     * @param  list<array{credited_at: string, amount: string, wallet_transaction_public_ref: ?string, destination: ?FinancialDestinationDTO}>  $recentCredits
     */
    public function __construct(
        public string $pendingTotal,
        public string $eligibleTotal,
        public string $creditedTotal,
        public string $creditedThisMonth,
        public string $failedTotal,
        public string $generatedTotal,
        public int $pendingCount,
        public int $creditedCount,
        public int $failedCount,
        public string $walletAvailableToSpend,
        public string $walletCurrency,
        public string $payoutThreshold,
        public int $waitDays,
        public bool $canRequestPayout,
        public ?PayoutRequestStatus $payoutRequestStatus,
        public ?string $payoutRequestEligibleAmount,
        public ?string $payoutRequestCreatedAt,
        public array $items,
        public SalespersonEarningsFilters $filters,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
        public array $recentCredits,
        public bool $pricesVisible,
        public FinancialDestinationDTO $walletDestination,
        public FinancialDestinationDTO $transactionsDestination,
        public ?FinancialDestinationDTO $dashboardDestination,
    ) {}
}
