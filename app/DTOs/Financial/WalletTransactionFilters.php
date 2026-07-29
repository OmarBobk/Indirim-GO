<?php

declare(strict_types=1);

namespace App\DTOs\Financial;

use App\Enums\WalletTransactionType;

/**
 * Allowlisted filters for the customer wallet ledger (M6.2).
 */
final readonly class WalletTransactionFilters
{
    public const DIRECTIONS = ['all', 'credit', 'debit'];

    public const TYPES = [
        'all',
        'purchase',
        'topup',
        'refund',
        'adjustment',
        'commission_credit',
    ];

    public const SEARCH_MAX_LENGTH = 64;

    public const PER_PAGE = 20;

    public function __construct(
        public string $direction = 'all',
        public string $type = 'all',
        public string $search = '',
        public ?string $dateFrom = null,
        public ?string $dateTo = null,
        public int $page = 1,
        public int $perPage = self::PER_PAGE,
    ) {}

    /**
     * @param  array{
     *     direction?: mixed,
     *     type?: mixed,
     *     search?: mixed,
     *     date_from?: mixed,
     *     date_to?: mixed,
     *     page?: mixed,
     *     per_page?: mixed
     * }  $input
     */
    public static function fromInput(array $input): self
    {
        $direction = is_string($input['direction'] ?? null) ? strtolower(trim($input['direction'])) : 'all';
        if (! in_array($direction, self::DIRECTIONS, true)) {
            $direction = 'all';
        }

        $type = is_string($input['type'] ?? null) ? strtolower(trim($input['type'])) : 'all';
        if (! in_array($type, self::TYPES, true)) {
            $type = 'all';
        }

        $search = is_string($input['search'] ?? null) ? trim($input['search']) : '';
        if (mb_strlen($search) > self::SEARCH_MAX_LENGTH) {
            $search = mb_substr($search, 0, self::SEARCH_MAX_LENGTH);
        }

        $dateFrom = self::normalizeDate($input['date_from'] ?? null);
        $dateTo = self::normalizeDate($input['date_to'] ?? null);

        if ($dateFrom !== null && $dateTo !== null && $dateFrom > $dateTo) {
            [$dateFrom, $dateTo] = [$dateTo, $dateFrom];
        }

        $page = max(1, (int) ($input['page'] ?? 1));
        $perPage = self::PER_PAGE;

        return new self(
            direction: $direction,
            type: $type,
            search: $search,
            dateFrom: $dateFrom,
            dateTo: $dateTo,
            page: $page,
            perPage: $perPage,
        );
    }

    public function typeEnum(): ?WalletTransactionType
    {
        return match ($this->type) {
            'purchase' => WalletTransactionType::Purchase,
            'topup' => WalletTransactionType::Topup,
            'refund' => WalletTransactionType::Refund,
            'adjustment' => WalletTransactionType::Adjustment,
            'commission_credit' => WalletTransactionType::CommissionCredit,
            default => null,
        };
    }

    public function hasActiveFilters(): bool
    {
        return $this->direction !== 'all'
            || $this->type !== 'all'
            || $this->search !== ''
            || $this->dateFrom !== null
            || $this->dateTo !== null;
    }

    private static function normalizeDate(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $value = trim($value);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        return $value;
    }
}
