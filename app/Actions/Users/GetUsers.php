<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use App\Support\UserRegistrationSourceResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class GetUsers
{
    public function handle(
        string $search,
        string $statusFilter,
        string $sortBy,
        string $sortDirection,
        int $perPage,
        int $page = 1
    ): LengthAwarePaginator {
        $search = trim($search);

        $query = User::query()
            ->select([
                'id',
                'name',
                'username',
                'email',
                'phone',
                'country_code',
                'referred_by_user_id',
                'email_verified_at',
                'blocked_at',
                'last_login_at',
                'created_at',
            ]);

        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $q) use ($like): void {
                $q->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('username', 'like', $like);
            });
        }

        if ($statusFilter === 'active') {
            $query->whereNull('blocked_at');
        }

        if ($statusFilter === 'blocked') {
            $query->whereNotNull('blocked_at');
        }

        $sortColumn = match ($sortBy) {
            'last_login_at' => 'last_login_at',
            'status' => 'blocked_at',
            default => 'created_at',
        };

        $direction = $sortDirection === 'desc' ? 'desc' : 'asc';
        if ($sortColumn === 'blocked_at') {
            $query->orderByRaw('blocked_at is null '.($direction === 'asc' ? 'desc' : 'asc'));
            $query->orderBy('blocked_at', $direction);
        } else {
            $query->orderBy($sortColumn, $direction);
        }

        $paginator = $query
            ->with([
                'roles:id,name',
                'referredBy:id,username,name',
            ])
            ->paginate($perPage, ['*'], 'page', $page);

        $sources = UserRegistrationSourceResolver::resolveForUserIds(
            $paginator->getCollection()->pluck('id')->all()
        );

        $paginator->getCollection()->transform(function (User $user) use ($sources): User {
            $source = $sources[$user->id] ?? null;
            $user->setAttribute(
                'registration_summary',
                $source !== null ? $source->describe($user) : ''
            );

            return $user;
        });

        return $paginator;
    }
}
