<?php

declare(strict_types=1);

namespace App\Actions\Home;

use App\Enums\OrderStatus;
use App\Models\Category;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single query owner for the authenticated customer homepage read model.
 * Ownership and eager-load shape live here — no presentation mapping.
 */
final class GetCustomerHome
{
    public const FREQUENTLY_ORDERED_LIMIT = 8;

    public const CATEGORY_LIMIT = 12;

    public const PACKAGE_LIMIT = 8;

    /**
     * @return array{
     *     userId: int,
     *     frequentlyOrdered: list<array{package: Package, times_ordered: int}>,
     *     categories: Collection<int, Category>,
     *     packages: Collection<int, Package>
     * }
     */
    public function handle(
        User $user,
        int $frequentlyOrderedLimit = self::FREQUENTLY_ORDERED_LIMIT,
        int $categoryLimit = self::CATEGORY_LIMIT,
        int $packageLimit = self::PACKAGE_LIMIT,
    ): array {
        return [
            'userId' => (int) $user->id,
            'frequentlyOrdered' => $this->frequentlyOrdered($user, $frequentlyOrderedLimit),
            'categories' => $this->categories($categoryLimit),
            'packages' => $this->packages($packageLimit),
        ];
    }

    /**
     * @return list<array{package: Package, times_ordered: int}>
     */
    public function frequentlyOrdered(User $user, int $limit = self::FREQUENTLY_ORDERED_LIMIT): array
    {
        $ranked = OrderItem::query()
            ->select([
                'order_items.package_id',
                DB::raw('COUNT(*) as times_ordered'),
                DB::raw('MAX(order_items.id) as latest_item_id'),
            ])
            ->join('orders', 'orders.id', '=', 'order_items.order_id')
            ->where('orders.user_id', $user->id)
            ->whereNotNull('order_items.package_id')
            ->whereIn('orders.status', [
                OrderStatus::Paid->value,
                OrderStatus::Processing->value,
                OrderStatus::Fulfilled->value,
            ])
            ->groupBy('order_items.package_id')
            ->orderByDesc('times_ordered')
            ->orderByDesc('latest_item_id')
            ->limit($limit)
            ->get();

        if ($ranked->isEmpty()) {
            return [];
        }

        $packages = Package::query()
            ->select(['id', 'name', 'image'])
            ->whereIn('id', $ranked->pluck('package_id'))
            ->where('is_active', true)
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->get()
            ->keyBy('id');

        $items = [];

        foreach ($ranked as $row) {
            $package = $packages->get((int) $row->package_id);

            if ($package === null) {
                continue;
            }

            $items[] = [
                'package' => $package,
                'times_ordered' => (int) $row->times_ordered,
            ];
        }

        return $items;
    }

    /**
     * @return Collection<int, Category>
     */
    public function categories(int $limit = self::CATEGORY_LIMIT): Collection
    {
        return Category::query()
            ->select(['id', 'name', 'slug', 'image', 'order'])
            ->whereNull('parent_id')
            ->where('is_active', true)
            ->orderBy('order')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Package>
     */
    public function packages(int $limit = self::PACKAGE_LIMIT): Collection
    {
        return Package::query()
            ->select(['id', 'name', 'image', 'order'])
            ->where('is_active', true)
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('order')
            ->orderBy('name')
            ->limit($limit)
            ->get();
    }
}
