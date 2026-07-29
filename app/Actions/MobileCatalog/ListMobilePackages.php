<?php

declare(strict_types=1);

namespace App\Actions\MobileCatalog;

use App\Models\Package;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\Api\V1\MobileCatalogPricer;
use App\Support\Api\V1\SafePublicAssetUrl;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final class ListMobilePackages
{
    public const DEFAULT_PER_PAGE = 24;

    public const MAX_PER_PAGE = 50;

    public const MIN_QUERY_LENGTH = 2;

    public const MAX_QUERY_LENGTH = 100;

    /**
     * @return array{
     *     data: list<array{
     *         id: int,
     *         name: string,
     *         slug: string,
     *         image_url: string|null,
     *         products_count: int,
     *         from_price: array{amount: string, currency: string, display: array{currency: string, formatted: string}}|null,
     *         category: array{id: int, name: string, slug: string}|null
     *     }>,
     *     meta: array{
     *         prices_visible: bool,
     *         pagination: array{page: int, per_page: int, total: int, last_page: int}
     *     }
     * }
     */
    public function handle(
        User $user,
        ?int $categoryId,
        ?string $query,
        int $page,
        int $perPage,
    ): array {
        $pricesVisible = WebsiteSetting::getPricesVisible();
        $pricer = MobileCatalogPricer::for($user, $pricesVisible);

        $builder = Package::query()
            ->select(['id', 'category_id', 'name', 'slug', 'image', 'order', 'is_active'])
            ->where('is_active', true)
            ->whereHas('products', fn ($q) => $q->where('is_active', true))
            ->with([
                'category:id,name,slug,is_active',
                'products' => fn ($q) => $q
                    ->select([
                        'id',
                        'package_id',
                        'name',
                        'amount_mode',
                        'custom_amount_min',
                        'custom_amount_max',
                        'custom_amount_step',
                        'amount_unit_label',
                        'entry_price',
                        'order',
                        'is_active',
                    ])
                    ->where('is_active', true)
                    ->orderBy('order')
                    ->orderBy('name'),
            ])
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('order')
            ->orderBy('name');

        if ($categoryId !== null) {
            // Exact category match only — storefront category pages do not include descendants.
            $builder->where('category_id', $categoryId);
        }

        if ($query !== null && $query !== '') {
            $builder->where(function ($q) use ($query): void {
                $q->where('name', 'like', '%'.$query.'%')
                    ->orWhere('description', 'like', '%'.$query.'%');
            });
        }

        /** @var LengthAwarePaginator<int, Package> $paginator */
        $paginator = $builder->paginate(perPage: $perPage, page: $page);

        $data = collect($paginator->items())->map(function (Package $package) use ($pricer): array {
            $category = $package->category;

            return [
                'id' => (int) $package->id,
                'name' => (string) $package->name,
                'slug' => (string) $package->slug,
                'image_url' => SafePublicAssetUrl::fromRelativePath($package->image),
                'products_count' => (int) $package->products_count,
                'from_price' => $pricer->money($pricer->packageFromPrice($package->products)),
                'category' => $category !== null && $category->is_active
                    ? [
                        'id' => (int) $category->id,
                        'name' => (string) $category->name,
                        'slug' => (string) $category->slug,
                    ]
                    : null,
            ];
        })->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'prices_visible' => $pricesVisible,
                'pagination' => [
                    'page' => (int) $paginator->currentPage(),
                    'per_page' => (int) $paginator->perPage(),
                    'total' => (int) $paginator->total(),
                    'last_page' => (int) $paginator->lastPage(),
                ],
            ],
        ];
    }
}
