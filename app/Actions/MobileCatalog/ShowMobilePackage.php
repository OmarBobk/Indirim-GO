<?php

declare(strict_types=1);

namespace App\Actions\MobileCatalog;

use App\Enums\ProductAmountMode;
use App\Models\Package;
use App\Models\Product;
use App\Models\User;
use App\Models\WebsiteSetting;
use App\Support\Api\V1\MobileCatalogPricer;
use App\Support\Api\V1\SafePublicAssetUrl;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowMobilePackage
{
    /**
     * @return array{
     *     data: array{
     *         id: int,
     *         name: string,
     *         slug: string,
     *         description: string|null,
     *         image_url: string|null,
     *         products_count: int,
     *         from_price: array{amount: string, currency: string, display: array{currency: string, formatted: string}}|null,
     *         category: array{id: int, name: string, slug: string}|null,
     *         products: list<array{
     *             id: int,
     *             name: string,
     *             amount_mode: string,
     *             unit_price: array{amount: string, currency: string, display: array{currency: string, formatted: string}}|null,
     *             custom_amount: array{min: int, max: int|null, step: int|null, unit_label: string|null}|null,
     *             minimum_price: array{amount: string, currency: string, display: array{currency: string, formatted: string}}|null
     *         }>
     *     },
     *     meta: array{prices_visible: bool}
     * }
     */
    public function handle(User $user, int $packageId): array
    {
        $package = Package::query()
            ->select(['id', 'category_id', 'name', 'slug', 'description', 'image', 'order', 'is_active'])
            ->whereKey($packageId)
            ->where('is_active', true)
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
            ->first();

        if ($package === null) {
            throw new NotFoundHttpException('package_not_found');
        }

        $pricesVisible = WebsiteSetting::getPricesVisible();
        $pricer = MobileCatalogPricer::for($user, $pricesVisible);
        $category = $package->category;

        $products = $package->products->map(function (Product $product) use ($pricer): array {
            $mode = $product->amount_mode ?? ProductAmountMode::Fixed;
            $isCustom = $mode === ProductAmountMode::Custom;

            return [
                'id' => (int) $product->id,
                'name' => (string) $product->name,
                'amount_mode' => $mode->value,
                'unit_price' => $isCustom ? null : $pricer->money($pricer->fixedUnitPrice($product)),
                'custom_amount' => $isCustom
                    ? [
                        'min' => $product->custom_amount_min !== null ? (int) $product->custom_amount_min : null,
                        'max' => $product->custom_amount_max !== null ? (int) $product->custom_amount_max : null,
                        'step' => $product->custom_amount_step !== null ? (int) $product->custom_amount_step : null,
                        'unit_label' => $product->amount_unit_label,
                    ]
                    : null,
                'minimum_price' => $isCustom
                    ? $pricer->money($pricer->customMinimumTotal($product))
                    : null,
            ];
        })->values()->all();

        return [
            'data' => [
                'id' => (int) $package->id,
                'name' => (string) $package->name,
                'slug' => (string) $package->slug,
                'description' => $package->description,
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
                'products' => $products,
            ],
            'meta' => [
                'prices_visible' => $pricesVisible,
            ],
        ];
    }
}
