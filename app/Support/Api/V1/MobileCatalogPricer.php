<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Enums\ProductAmountMode;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerPriceService;
use InvalidArgumentException;

/**
 * Request-scoped catalog pricing over {@see CustomerPriceService}.
 * Does not change pricing math; memoizes identical product calculations only.
 */
final class MobileCatalogPricer
{
    /** @var array<string, float|null> */
    private array $memo = [];

    public function __construct(
        private readonly CustomerPriceService $priceService,
        private readonly User $user,
        private readonly bool $pricesVisible,
        private readonly MobileMoneyFactory $moneyFactory,
    ) {}

    public static function for(User $user, bool $pricesVisible): self
    {
        return new self(
            app(CustomerPriceService::class),
            $user,
            $pricesVisible,
            MobileMoneyFactory::forUser($user),
        );
    }

    public function pricesVisible(): bool
    {
        return $this->pricesVisible;
    }

    /**
     * @return array{amount: string, currency: string, display: array{currency: string, formatted: string}}|null
     */
    public function money(?float $usdAmount): ?array
    {
        if (! $this->pricesVisible || $usdAmount === null) {
            return null;
        }

        return $this->moneyFactory->fromUsdAmount($usdAmount);
    }

    public function fixedUnitPrice(Product $product): ?float
    {
        if (! $this->pricesVisible) {
            return null;
        }

        return $this->memoized("fixed:{$product->id}", function () use ($product): ?float {
            try {
                return $this->priceService->finalPrice($product, $this->user);
            } catch (InvalidArgumentException) {
                return null;
            }
        });
    }

    public function customMinimumTotal(Product $product): ?float
    {
        if (! $this->pricesVisible) {
            return null;
        }

        $minimum = $this->resolvedCustomMinimum($product);

        if ($minimum === null) {
            return null;
        }

        return $this->memoized("custom:{$product->id}:{$minimum}", function () use ($product, $minimum): ?float {
            try {
                return (float) $this->priceService->finalPriceForAmount($product, $minimum, $this->user)['final_price'];
            } catch (InvalidArgumentException) {
                return null;
            }
        });
    }

    /**
     * Minimum currently purchasable total across active products.
     *
     * @param  iterable<int, Product>  $products
     */
    public function packageFromPrice(iterable $products): ?float
    {
        if (! $this->pricesVisible) {
            return null;
        }

        $minimum = null;

        foreach ($products as $product) {
            $total = $this->purchasableTotal($product);

            if ($total === null) {
                continue;
            }

            if ($minimum === null || $total < $minimum) {
                $minimum = $total;
            }
        }

        return $minimum;
    }

    public function purchasableTotal(Product $product): ?float
    {
        $mode = $product->amount_mode ?? ProductAmountMode::Fixed;

        if ($mode === ProductAmountMode::Custom) {
            return $this->customMinimumTotal($product);
        }

        return $this->fixedUnitPrice($product);
    }

    private function resolvedCustomMinimum(Product $product): ?int
    {
        $minimum = $product->custom_amount_min;

        if ($minimum === null || (int) $minimum <= 0) {
            return null;
        }

        return (int) $minimum;
    }

    /**
     * @param  callable(): (?float)  $resolver
     */
    private function memoized(string $key, callable $resolver): ?float
    {
        if (! array_key_exists($key, $this->memo)) {
            $this->memo[$key] = $resolver();
        }

        return $this->memo[$key];
    }
}
