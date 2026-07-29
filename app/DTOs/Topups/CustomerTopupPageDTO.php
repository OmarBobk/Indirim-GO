<?php

declare(strict_types=1);

namespace App\DTOs\Topups;

/**
 * Paginated customer top-up list result.
 *
 * @phpstan-type TimelineEvent array{key: string, label_key: string, occurred_at: string|null}
 */
final readonly class CustomerTopupPageDTO
{
    /**
     * @param  list<CustomerTopupRequestDTO>  $items
     */
    public function __construct(
        public array $items,
        public CustomerTopupFilters $filters,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
        public bool $pricesVisible,
        public ?string $pendingTopupPublicRef,
    ) {}
}
