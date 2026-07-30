<?php

declare(strict_types=1);

namespace App\DTOs\Earnings;

/**
 * Filters for salesperson earnings commission list (M6.6).
 */
final readonly class SalespersonEarningsFilters
{
    public const PER_PAGE = 20;

    public const MAX_SEARCH = 64;

    /**
     * @param  'all'|'pending'|'credited'|'failed'  $status
     */
    public function __construct(
        public string $status = 'all',
        public string $search = '',
        public int $page = 1,
        public int $perPage = self::PER_PAGE,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function fromInput(array $input): self
    {
        $status = is_string($input['status'] ?? null) ? strtolower(trim($input['status'])) : 'all';
        if (! in_array($status, ['all', 'pending', 'credited', 'failed'], true)) {
            $status = 'all';
        }

        $search = is_string($input['search'] ?? null) ? trim($input['search']) : '';
        if (mb_strlen($search) > self::MAX_SEARCH) {
            $search = mb_substr($search, 0, self::MAX_SEARCH);
        }

        $page = max(1, (int) ($input['page'] ?? 1));

        return new self(
            status: $status,
            search: $search,
            page: $page,
            perPage: self::PER_PAGE,
        );
    }

    public function hasActiveFilters(): bool
    {
        return $this->status !== 'all' || $this->search !== '';
    }
}
