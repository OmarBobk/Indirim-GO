<?php

declare(strict_types=1);

namespace App\Actions\MobileCatalog;

use App\Actions\Home\GetCustomerHome;
use App\Models\Package;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\Api\V1\MobileCatalogPricer;
use App\Support\Api\V1\SafePublicAssetUrl;
use Illuminate\Support\Collection;

/**
 * Authenticated mobile catalog home shelves.
 *
 * @phpstan-type PackageSummary array{
 *     id: int,
 *     name: string,
 *     slug: string,
 *     image_url: string|null,
 *     products_count: int,
 *     from_price: array{amount: string, currency: string, display: array{currency: string, formatted: string}}|null,
 *     category: array{id: int, name: string, slug: string}|null,
 *     times_ordered?: int
 * }
 */
final class BuildCatalogHome
{
    public function __construct(
        private readonly GetCustomerHome $getCustomerHome,
    ) {}

    /**
     * @return array{
     *     data: array{
     *         frequently_ordered: list<PackageSummary>,
     *         featured_packages: list<PackageSummary>,
     *         categories: list<array{id: int, name: string, slug: string, image_url: string|null}>
     *     },
     *     meta: array{prices_visible: bool}
     * }
     */
    public function handle(User $user): array
    {
        $home = $this->getCustomerHome->handle($user);
        $pricesVisible = WebsiteSetting::getPricesVisible();
        $pricer = MobileCatalogPricer::for($user, $pricesVisible);

        $frequentlyOrderedIds = collect($home['frequentlyOrdered'])
            ->map(fn (array $row): int => (int) $row['package']->id)
            ->all();
        $featuredIds = $home['packages']->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $allIds = array_values(array_unique([...$frequentlyOrderedIds, ...$featuredIds]));

        $packages = $this->hydratePackages($allIds);
        $frequentlyOrdered = [];

        foreach ($home['frequentlyOrdered'] as $row) {
            $package = $packages->get((int) $row['package']->id);

            if ($package === null || (int) $package->products_count < 1) {
                continue;
            }

            $summary = $this->summarize($package, $pricer);
            $summary['times_ordered'] = (int) $row['times_ordered'];
            $frequentlyOrdered[] = $summary;
        }

        $featured = [];

        foreach ($home['packages'] as $listed) {
            $package = $packages->get((int) $listed->id);

            if ($package === null || (int) $package->products_count < 1) {
                continue;
            }

            $featured[] = $this->summarize($package, $pricer);
        }

        return [
            'data' => [
                'frequently_ordered' => $frequentlyOrdered,
                'featured_packages' => $featured,
                'categories' => $home['categories']->map(fn ($category): array => [
                    'id' => (int) $category->id,
                    'name' => (string) $category->name,
                    'slug' => (string) $category->slug,
                    'image_url' => SafePublicAssetUrl::fromRelativePath($category->image),
                ])->values()->all(),
            ],
            'meta' => [
                'prices_visible' => $pricesVisible,
            ],
        ];
    }

    /**
     * @param  list<int>  $ids
     * @return Collection<int, Package>
     */
    private function hydratePackages(array $ids): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return Package::query()
            ->select(['id', 'category_id', 'name', 'slug', 'image', 'order', 'is_active'])
            ->with([
                'category:id,name,slug,is_active',
                'products' => fn ($query) => $query
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
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->whereIn('id', $ids)
            ->where('is_active', true)
            ->get()
            ->keyBy('id');
    }

    /**
     * @return PackageSummary
     */
    private function summarize(Package $package, MobileCatalogPricer $pricer): array
    {
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
    }
}
