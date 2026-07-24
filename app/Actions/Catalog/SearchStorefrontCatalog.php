<?php

declare(strict_types=1);

namespace App\Actions\Catalog;

use App\Models\Category;
use App\Models\Package;
use App\Models\Product;
use Illuminate\Database\Eloquent\Builder;

class SearchStorefrontCatalog
{
    private const int LIMIT_PER_GROUP = 24;

    /**
     * @return array{
     *     categories: array<int, array<string, mixed>>,
     *     packages: array<int, array<string, mixed>>,
     *     products: array<int, array<string, mixed>>
     * }
     */
    public function handle(string $search): array
    {
        $search = trim($search);
        if ($search === '') {
            return [
                'categories' => [],
                'packages' => [],
                'products' => [],
            ];
        }

        $like = '%'.$search.'%';
        $placeholderImage = asset('images/icons/category-placeholder.svg');

        $categories = Category::query()
            ->select(['id', 'name', 'slug', 'image'])
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q->where('name', 'like', $like))
            ->orderBy('order')
            ->orderBy('name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Category $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'slug' => $category->slug,
                'image' => filled($category->image) ? asset($category->image) : $placeholderImage,
            ])
            ->values()
            ->all();

        $packages = Package::query()
            ->select(['id', 'name', 'image', 'order', 'category_id'])
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('description', 'like', $like))
            ->with('category:id,name')
            ->withCount(['products' => fn (Builder $q) => $q->where('is_active', true)])
            ->orderBy('order')
            ->orderBy('name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Package $package): array => [
                'id' => $package->id,
                'name' => $package->name,
                'image' => filled($package->image) ? asset($package->image) : $placeholderImage,
                'products_count' => (int) $package->products_count,
                'category_name' => $package->category?->name,
            ])
            ->values()
            ->all();

        $products = Product::query()
            ->select(['id', 'name', 'package_id', 'order'])
            ->where('is_active', true)
            ->where(fn (Builder $q) => $q
                ->where('name', 'like', $like)
                ->orWhere('serial', 'like', $like))
            ->whereHas('package', fn (Builder $q) => $q->where('is_active', true))
            ->with(['package:id,name,image,is_active'])
            ->orderBy('order')
            ->orderBy('name')
            ->limit(self::LIMIT_PER_GROUP)
            ->get()
            ->map(fn (Product $product): array => [
                'id' => $product->id,
                'name' => $product->name,
                'package_id' => $product->package_id,
                'package_name' => $product->package?->name ?? '',
                'image' => filled($product->package?->image)
                    ? asset((string) $product->package->image)
                    : $placeholderImage,
            ])
            ->values()
            ->all();

        return [
            'categories' => $categories,
            'packages' => $packages,
            'products' => $products,
        ];
    }
}
