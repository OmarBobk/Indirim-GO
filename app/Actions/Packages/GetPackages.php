<?php

namespace App\Actions\Packages;

use App\Models\Package;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class GetPackages
{
    public function handle(
        string $search,
        string $statusFilter,
        string $sortBy,
        string $sortDirection,
        int $perPage
    ): LengthAwarePaginator {
        $search = trim($search);

        $query = Package::query()
            ->select([
                'id',
                'category_id',
                'name',
                'slug',
                'description',
                'is_active',
                'fulfillment_provider',
                'order',
                'icon',
                'image',
                'created_at',
            ]);

        if ($search !== '') {
            $query->where(function (Builder $query) use ($search): void {
                $like = '%'.$search.'%';

                $query->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like);
            });
        }

        if ($statusFilter === 'active') {
            $query->where('is_active', true);
        }

        if ($statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        $sortColumn = match ($sortBy) {
            'name' => 'name',
            'created_at' => 'created_at',
            default => 'order',
        };

        $sortDirection = $sortDirection === 'desc' ? 'desc' : 'asc';

        return $query
            ->with(['category:id,name,slug'])
            ->withCount('requirements')
            ->orderBy($sortColumn, $sortDirection)
            ->paginate($perPage);
    }
}
