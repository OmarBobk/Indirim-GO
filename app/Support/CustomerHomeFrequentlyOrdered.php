<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\OrderStatus;
use App\Models\OrderItem;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Presentation query for authenticated home "Frequently Ordered" packages.
 * Does not change pricing or checkout — returns active packages for overlay open.
 */
final class CustomerHomeFrequentlyOrdered
{
    public const LIMIT = 8;

    /**
     * @return list<array{id: int, name: string, image: string, products_count: int, times_ordered: int}>
     */
    public static function forUser(User $user, int $limit = self::LIMIT): array
    {
        $placeholderImage = asset('images/icons/category-placeholder.svg');

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
                'id' => (int) $package->id,
                'name' => (string) $package->name,
                'image' => filled($package->image) ? asset($package->image) : $placeholderImage,
                'products_count' => (int) $package->products_count,
                'times_ordered' => (int) $row->times_ordered,
            ];
        }

        return $items;
    }
}
