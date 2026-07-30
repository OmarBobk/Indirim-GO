<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Domain\Pricing\CustomAmountValidator;
use App\Enums\ProductAmountMode;
use App\Models\Product;
use App\Models\User;
use App\Services\CustomerPriceService;
use App\Services\PriceCalculator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Request-scoped catalog pricing over {@see CustomerPriceService}.
 *
 * Creates a fresh PriceCalculator + CustomerPriceService per catalog request,
 * warms reusable pricing rules / loyalty lookups on that instance only, and
 * memoizes identical product totals. Never stores final customer prices in a
 * shared cache and never reuses warmed state across users or requests.
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
        private readonly CustomAmountValidator $customAmountValidator,
    ) {}

    public static function for(User $user, bool $pricesVisible): self
    {
        $user->loadMissing('roles');

        $calculator = (new PriceCalculator)->warmForUser($user);
        $priceService = new CustomerPriceService($calculator, memoizeTierConfig: true);

        return new self(
            $priceService,
            $user,
            $pricesVisible,
            MobileMoneyFactory::forUser($user),
            app(CustomAmountValidator::class),
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

        $minimum = $this->resolvedPurchasableCustomMinimum($product);

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
     * Schema-safe custom amount metadata. Invalid numeric bounds become null
     * so OpenAPI `minimum: 1` fields are never violated; checkout remains authoritative.
     *
     * @return array{min: int|null, max: int|null, step: int|null, unit_label: string|null}
     */
    public function customAmountMeta(Product $product): array
    {
        return [
            'min' => $this->positiveOrNull($product->custom_amount_min),
            'max' => $this->positiveOrNull($product->custom_amount_max),
            'step' => $this->positiveOrNull($product->custom_amount_step),
            'unit_label' => $product->amount_unit_label,
        ];
    }

    /**
     * Minimum currently purchasable total across active products.
     * Invalid custom configurations never contribute.
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

    /**
     * Returns the product's configured minimum only when checkout would accept it.
     */
    private function resolvedPurchasableCustomMinimum(Product $product): ?int
    {
        $minimum = $product->custom_amount_min;

        if ($minimum === null || (int) $minimum <= 0) {
            return null;
        }

        try {
            return $this->customAmountValidator->validate($product, (int) $minimum);
        } catch (ValidationException) {
            return null;
        }
    }

    private function positiveOrNull(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $int = (int) $value;

        return $int >= 1 ? $int : null;
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
