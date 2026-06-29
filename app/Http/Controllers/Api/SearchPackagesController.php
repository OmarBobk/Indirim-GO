<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Package;
use App\Models\WebsiteSetting;
use App\Services\CustomerPriceService;
use App\Support\FrontendMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SearchPackagesController extends Controller
{
    private const LIMIT = 24;

    private const MIN_QUERY_LENGTH = 2;

    public function __invoke(Request $request, CustomerPriceService $priceService): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:'.self::MIN_QUERY_LENGTH, 'max:100'],
        ]);

        $term = trim($validated['q']);
        $placeholderImage = asset('images/icons/category-placeholder.svg');
        $user = $request->user();
        $pricesVisible = WebsiteSetting::getPricesVisible();
        $money = FrontendMoney::for($user);

        $packages = Package::query()
            ->select(['id', 'name', 'image', 'order'])
            ->where('is_active', true)
            ->where(function ($query) use ($term): void {
                $query->where('name', 'like', '%'.$term.'%')
                    ->orWhere('description', 'like', '%'.$term.'%');
            })
            ->whereHas('products', fn ($query) => $query->where('is_active', true))
            ->with([
                'products' => fn ($query) => $query
                    ->select([
                        'id',
                        'package_id',
                        'retail_price',
                        'entry_price',
                        'amount_mode',
                        'order',
                    ])
                    ->where('is_active', true)
                    ->orderBy('order')
                    ->orderBy('name'),
            ])
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('order')
            ->orderBy('name')
            ->limit(self::LIMIT)
            ->get();

        $results = $packages->map(function (Package $package) use (
            $placeholderImage,
            $priceService,
            $user,
            $pricesVisible,
            $money
        ): array {
            $fromPrice = null;
            $fromPriceLabel = null;

            if ($pricesVisible) {
                foreach ($package->products as $product) {
                    $prices = $priceService->priceFor($product, $user);
                    $final = (float) $prices['final_price'];

                    if ($fromPrice === null || $final < $fromPrice) {
                        $fromPrice = $final;
                    }
                }

                if ($fromPrice !== null) {
                    $fromPriceLabel = $money->format($fromPrice, 'USD', 2);
                }
            }

            return [
                'id' => $package->id,
                'name' => $package->name,
                'image' => filled($package->image) ? asset($package->image) : $placeholderImage,
                'products_count' => $package->products_count,
                'from_price' => $fromPrice,
                'from_price_label' => $fromPriceLabel,
            ];
        })->values()->all();

        return response()->json([
            'data' => $results,
        ]);
    }
}
