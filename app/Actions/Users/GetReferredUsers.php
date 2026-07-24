<?php

declare(strict_types=1);

namespace App\Actions\Users;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class GetReferredUsers
{
    public function handle(
        int $referrerUserId,
        string $search,
        string $statusFilter,
        string $sortBy,
        string $sortDirection,
        int $perPage,
        int $page = 1
    ): LengthAwarePaginator {
        $search = trim($search);

        $query = User::query()
            ->where('referred_by_user_id', $referrerUserId)
            ->select([
                'id',
                'name',
                'username',
                'email',
                'phone',
                'country_code',
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

        return $query
            ->with('roles:id,name')
            ->paginate($perPage, ['*'], 'page', $page);
    }
}
