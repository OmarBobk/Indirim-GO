<?php

declare(strict_types=1);

namespace App\DTOs\Topups;

use App\Enums\TopupRequestStatus;

/**
 * Allowlisted filters for the customer top-up workspace (M6.3).
 */
final readonly class CustomerTopupFilters
{
    public const FILTERS = [
        'all',
        'under_review',
        'credited',
        'needs_action',
        'closed',
    ];

    public const SEARCH_MAX_LENGTH = 64;

    public const PER_PAGE = 20;

    public function __construct(
        public string $filter = 'all',
        public string $search = '',
        public int $page = 1,
        public int $perPage = self::PER_PAGE,
    ) {}

    /**
     * @param  array{filter?: mixed, search?: mixed, page?: mixed}  $input
     */
    public static function fromInput(array $input): self
    {
        $filter = is_string($input['filter'] ?? null) ? strtolower(trim($input['filter'])) : 'all';
        if (! in_array($filter, self::FILTERS, true)) {
            $filter = 'all';
        }

        $search = is_string($input['search'] ?? null) ? trim($input['search']) : '';
        if (mb_strlen($search) > self::SEARCH_MAX_LENGTH) {
            $search = mb_substr($search, 0, self::SEARCH_MAX_LENGTH);
        }

        return new self(
            filter: $filter,
            search: $search,
            page: max(1, (int) ($input['page'] ?? 1)),
            perPage: self::PER_PAGE,
        );
    }

    /**
     * @return list<TopupRequestStatus>|null
     */
    public function statusEnums(): ?array
    {
        return match ($this->filter) {
            'under_review' => [TopupRequestStatus::Pending],
            'credited' => [TopupRequestStatus::Approved],
            'needs_action' => [TopupRequestStatus::Rejected],
            'closed' => [TopupRequestStatus::Cancelled],
            default => null,
        };
    }

    public function hasActiveFilters(): bool
    {
        return $this->filter !== 'all' || $this->search !== '';
    }
}
