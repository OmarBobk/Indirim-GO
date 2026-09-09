<?php

declare(strict_types=1);

namespace App\Actions\MobileWallet;

use App\Actions\Financial\GetCustomerWalletTransactions;
use App\DTOs\Financial\WalletTransactionDTO;
use App\DTOs\Financial\WalletTransactionFilters;
use App\Models\User;
use App\Support\Api\V1\MobileTopupFactory;

final class ListMobileWalletTransactions
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly GetCustomerWalletTransactions $ledger,
    ) {}

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{pagination: array{page: int, per_page: int, total: int, last_page: int}}}
     */
    public function handle(User $user, int $page, int $perPage): array
    {
        $pageResult = $this->ledger->handle($user, new WalletTransactionFilters(
            page: $page,
            perPage: $perPage,
        ));
        $factory = MobileTopupFactory::forUser($user);

        return [
            'data' => array_map(
                fn (WalletTransactionDTO $item): array => $factory->fromLedgerItem($item),
                $pageResult->items,
            ),
            'meta' => [
                'pagination' => [
                    'page' => $pageResult->currentPage,
                    'per_page' => $pageResult->perPage,
                    'total' => $pageResult->total,
                    'last_page' => $pageResult->lastPage,
                ],
            ],
        ];
    }
}
