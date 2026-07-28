<?php

declare(strict_types=1);

namespace App\Support\Activity;

use App\DTOs\CustomerActivityDTO;
use App\Enums\CustomerActivityCategory;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Pagination\LengthAwarePaginator as ConcretePaginator;

/**
 * Reads customer database notifications into Activity DTOs.
 */
final class NotificationActivityReader
{
    public function __construct(
        private readonly CustomerActivityNotificationMapper $mapper,
    ) {}

    /**
     * @return LengthAwarePaginator<int, CustomerActivityDTO>
     */
    public function paginate(
        User $user,
        string $filter = 'all',
        ?CustomerActivityCategory $category = null,
        int $perPage = 15,
        int $page = 1,
    ): LengthAwarePaginator {
        $perPage = max(1, min(50, $perPage));
        $page = max(1, $page);
        $filter = in_array($filter, ['all', 'unread'], true) ? $filter : 'all';

        $query = $user->notifications()
            ->getQuery()
            ->reorder()
            ->whereNotIn('type', $this->mapper->adminTypes())
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($filter === 'unread') {
            $query->whereNull('read_at');
        }

        if ($category !== null) {
            $types = $this->mapper->typesForCategory($category);

            if ($category === CustomerActivityCategory::Account) {
                // Unknown/legacy rows degrade to Account; include them in this filter.
                $query->where(function ($builder) use ($types): void {
                    if ($types !== []) {
                        $builder->whereIn('type', $types);
                    }

                    $builder->orWhereNotIn('type', $this->mapper->supportedTypes());
                });
            } elseif ($types === []) {
                return $this->emptyPaginator($perPage, $page);
            } else {
                $query->whereIn('type', $types);
            }
        }

        /** @var LengthAwarePaginator<int, DatabaseNotification> $paginator */
        $paginator = $query->paginate(perPage: $perPage, page: $page);

        $items = $paginator->getCollection()
            ->map(fn (DatabaseNotification $notification): CustomerActivityDTO => $this->mapper->map($notification))
            ->values()
            ->all();

        return new ConcretePaginator(
            $items,
            $paginator->total(),
            $paginator->perPage(),
            $paginator->currentPage(),
            [
                'path' => $paginator->path(),
                'pageName' => $paginator->getPageName(),
            ]
        );
    }

    public function unreadCount(User $user): int
    {
        return (int) $user->unreadNotifications()->count();
    }

    /**
     * Recent customer notifications for action-required twin attachment (not a feed page).
     *
     * @return list<CustomerActivityDTO>
     */
    public function recent(User $user, int $limit = 40): array
    {
        $limit = max(1, min(100, $limit));

        return $user->notifications()
            ->getQuery()
            ->reorder()
            ->whereNotIn('type', $this->mapper->adminTypes())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->map(fn (DatabaseNotification $notification): CustomerActivityDTO => $this->mapper->map($notification))
            ->values()
            ->all();
    }

    /**
     * @return LengthAwarePaginator<int, CustomerActivityDTO>
     */
    private function emptyPaginator(int $perPage, int $page): LengthAwarePaginator
    {
        return new ConcretePaginator([], 0, $perPage, $page);
    }
}
