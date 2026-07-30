<?php

declare(strict_types=1);

namespace App\DTOs\Financial;

/**
 * Paginated customer wallet ledger page result.
 *
 * @param  list<WalletTransactionDTO>  $items
 */
final readonly class WalletTransactionPageDTO
{
    /**
     * @param  list<WalletTransactionDTO>  $items
     */
    public function __construct(
        public array $items,
        public WalletTransactionFilters $filters,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
        public bool $showCommissionFilter,
        public bool $pricesVisible,
    ) {}
}
