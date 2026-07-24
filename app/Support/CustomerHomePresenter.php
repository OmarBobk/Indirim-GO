<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Category;
use App\Models\Package;
use App\Models\User;

/**
 * Presentation mapping for the authenticated customer homepage.
 * Does not query, authorize, or mutate domain state.
 */
final class CustomerHomePresenter
{
    public function __construct(
        private readonly User $user,
        private readonly string $placeholderImage,
    ) {}

    public static function for(?User $user = null): self
    {
        $resolved = $user ?? auth()->user();

        if ($resolved === null) {
            throw new \RuntimeException('CustomerHomePresenter requires an authenticated user.');
        }

        return new self(
            $resolved,
            asset('images/icons/category-placeholder.svg'),
        );
    }

    /**
     * @param  array{
     *     userId: int,
     *     frequentlyOrdered: list<array{package: Package, times_ordered: int}>,
     *     categories: \Illuminate\Support\Collection<int, Category>,
     *     packages: \Illuminate\Support\Collection<int, Package>
     * }  $home
     * @return array{
     *     command: array{},
     *     personal: array{items: list<array{id: int, name: string, image: string, products_count: int, times_ordered: int}>},
     *     browse: array{categories: list<array{id: int, name: string, slug: string, image: string}>},
     *     catalog: array{packages: list<array{id: int, name: string, image: string, products_count: int}>},
     *     merch: array{visible: false}
     * }
     */
    public function present(array $home): array
    {
        if ((int) $home['userId'] !== (int) $this->user->id) {
            throw new \InvalidArgumentException('Customer home read model user mismatch.');
        }

        return [
            'command' => [],
            'personal' => [
                'items' => $this->presentFrequentlyOrdered($home['frequentlyOrdered']),
            ],
            'browse' => [
                'categories' => $this->presentCategories($home['categories']),
            ],
            'catalog' => [
                'packages' => $this->presentPackages($home['packages']),
            ],
            'merch' => [
                'visible' => false,
            ],
        ];
    }

    /**
     * @param  list<array{package: Package, times_ordered: int}>  $rows
     * @return list<array{id: int, name: string, image: string, products_count: int, times_ordered: int}>
     */
    public function presentFrequentlyOrdered(array $rows): array
    {
        $items = [];

        foreach ($rows as $row) {
            $package = $row['package'];

            $items[] = [
                'id' => (int) $package->id,
                'name' => (string) $package->name,
                'image' => $this->imageUrl($package->image),
                'products_count' => (int) $package->products_count,
                'times_ordered' => (int) $row['times_ordered'],
            ];
        }

        return $items;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Category>|iterable<int, Category>  $categories
     * @return list<array{id: int, name: string, slug: string, image: string}>
     */
    public function presentCategories(iterable $categories): array
    {
        $out = [];

        foreach ($categories as $category) {
            $out[] = [
                'id' => (int) $category->id,
                'name' => (string) $category->name,
                'slug' => (string) $category->slug,
                'image' => $this->imageUrl($category->image),
            ];
        }

        return $out;
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Package>|iterable<int, Package>  $packages
     * @return list<array{id: int, name: string, image: string, products_count: int}>
     */
    public function presentPackages(iterable $packages): array
    {
        $out = [];

        foreach ($packages as $package) {
            $out[] = [
                'id' => (int) $package->id,
                'name' => (string) $package->name,
                'image' => $this->imageUrl($package->image),
                'products_count' => (int) $package->products_count,
            ];
        }

        return $out;
    }

    private function imageUrl(mixed $path): string
    {
        return filled($path) ? asset((string) $path) : $this->placeholderImage;
    }
}
