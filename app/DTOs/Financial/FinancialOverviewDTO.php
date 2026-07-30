<?php

declare(strict_types=1);

namespace App\DTOs\Financial;

/**
 * Customer Financial Overview read result (M6.1). Presentation-free.
 *
 * @phpstan-type PendingCounts array{
 *     pending_topups: int,
 *     pending_refunds: int,
 *     needs_customer_action: int
 * }
 */
final readonly class FinancialOverviewDTO
{
    /**
     * @param  list<PendingFinancialItemDTO>  $pendingItems
     * @param  list<RecentWalletTransactionDTO>  $recentTransactions
     * @param  array{pending_topups: int, pending_refunds: int, needs_customer_action: int}  $pendingCounts
     */
    public function __construct(
        public FinancialBalanceDTO $balance,
        public array $pendingItems,
        public bool $pendingHasMore,
        public array $pendingCounts,
        public array $recentTransactions,
        public bool $showSalespersonLink,
        public bool $pricesVisible,
        public ?string $purchaseResumeUrl,
        public bool $canAddFunds,
    ) {}
}
