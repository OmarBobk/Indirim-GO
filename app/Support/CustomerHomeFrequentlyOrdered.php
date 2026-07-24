<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Home\GetCustomerHome;
use App\Models\User;

/**
 * Backward-compatible facade for frequently-ordered rows.
 * Queries live in GetCustomerHome; mapping in CustomerHomePresenter.
 */
final class CustomerHomeFrequentlyOrdered
{
    public const LIMIT = GetCustomerHome::FREQUENTLY_ORDERED_LIMIT;

    /**
     * @return list<array{id: int, name: string, image: string, products_count: int, times_ordered: int}>
     */
    public static function forUser(User $user, int $limit = self::LIMIT): array
    {
        $rows = app(GetCustomerHome::class)->frequentlyOrdered($user, $limit);

        return CustomerHomePresenter::for($user)->presentFrequentlyOrdered($rows);
    }
}
