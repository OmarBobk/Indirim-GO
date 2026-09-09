<?php

declare(strict_types=1);

namespace App\Actions\MobileWallet;

use App\Actions\Topups\GetCustomerTopupRequests;
use App\DTOs\Topups\CustomerTopupFilters;
use App\DTOs\Topups\CustomerTopupRequestDTO;
use App\Models\User;
use App\Support\Api\V1\MobileTopupFactory;

final class ListMobileTopups
{
    public const DEFAULT_PER_PAGE = 20;

    public const MAX_PER_PAGE = 50;

    public function __construct(
        private readonly GetCustomerTopupRequests $list,
    ) {}

    /**
     * @return array{
     *     data: list<array<string, mixed>>,
     *     meta: array{
     *         pagination: array{page: int, per_page: int, total: int, last_page: int},
     *         pending_topup_public_ref: string|null
     *     }
     * }
     */
    public function handle(User $user, int $page, int $perPage): array
    {
        $pageResult = $this->list->handle($user, new CustomerTopupFilters(
            page: $page,
            perPage: $perPage,
        ));
        $factory = MobileTopupFactory::forUser($user);

        return [
            'data' => array_map(
                fn (CustomerTopupRequestDTO $item): array => $factory->fromListItem($item),
                $pageResult->items,
            ),
            'meta' => [
                'pagination' => [
                    'page' => $pageResult->currentPage,
                    'per_page' => $pageResult->perPage,
                    'total' => $pageResult->total,
                    'last_page' => $pageResult->lastPage,
                ],
                'pending_topup_public_ref' => $pageResult->pendingTopupPublicRef,
            ],
        ];
    }
}
