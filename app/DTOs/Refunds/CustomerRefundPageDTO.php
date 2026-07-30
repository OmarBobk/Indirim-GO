<?php

declare(strict_types=1);

namespace App\DTOs\Refunds;

/**
 * Paginated customer refund workspace page.
 *
 * @param  list<CustomerRefundDTO>  $items
 */
final readonly class CustomerRefundPageDTO
{
    /**
     * @param  list<CustomerRefundDTO>  $items
     */
    public function __construct(
        public array $items,
        public CustomerRefundFilters $filters,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
        public bool $pricesVisible,
    ) {}
}
