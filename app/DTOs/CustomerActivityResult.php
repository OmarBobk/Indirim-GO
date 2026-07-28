<?php

declare(strict_types=1);

namespace App\DTOs;

/**
 * Paginated Activity read-model result for Livewire / future HTTP.
 */
final readonly class CustomerActivityResult
{
    /**
     * @param  list<CustomerActivityDTO>  $items
     * @param  list<CustomerActivityDTO>  $actionRequiredSummary
     */
    public function __construct(
        public array $items,
        public int $currentPage,
        public int $perPage,
        public int $total,
        public int $lastPage,
        public int $unreadCount,
        public string $filter,
        public ?string $category,
        public array $actionRequiredSummary = [],
        public int $actionRequiredTotal = 0,
        public bool $hasMoreActionRequired = false,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'items' => array_map(
                static fn (CustomerActivityDTO $item): array => $item->toArray(),
                $this->items
            ),
            'currentPage' => $this->currentPage,
            'perPage' => $this->perPage,
            'total' => $this->total,
            'lastPage' => $this->lastPage,
            'unreadCount' => $this->unreadCount,
            'filter' => $this->filter,
            'category' => $this->category,
            'actionRequiredSummary' => array_map(
                static fn (CustomerActivityDTO $item): array => $item->toArray(),
                $this->actionRequiredSummary
            ),
            'actionRequiredTotal' => $this->actionRequiredTotal,
            'hasMoreActionRequired' => $this->hasMoreActionRequired,
        ];
    }
}
