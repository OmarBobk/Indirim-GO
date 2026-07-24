<?php

declare(strict_types=1);

use App\Actions\Orders\CheckoutFromPayload;
use App\Actions\Packages\ResolvePackageRequirements;
use App\Domain\Pricing\CustomAmountValidator;
use App\Domain\Pricing\PricingEngine;
use App\Enums\OrderStatus;
use App\Enums\ProductAmountMode;
use App\Models\Package;
use App\Models\Product;
use App\Models\Wallet;
use App\Support\BuyNowClientPricingContext;
use App\Support\BuyNowLoginIntent;
use App\Support\PurchaseResumeIntent;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

new class extends Component
{
    use Toastable;
    public bool $showBuyNowModal = false;
    public ?int $buyNowProductId = null;
    public ?string $buyNowProductName = null;
    public ?string $buyNowPackageName = null;
    public string $buyNowAmountMode = ProductAmountMode::Fixed->value;
    public ?string $buyNowAmountUnitLabel = null;
    public ?float $buyNowEntryPrice = null;
    /** @var int|string Livewire can send empty string from number input; we normalize to int. */
    public int|string|null $buyNowRequestedAmount = null;

    /**
     * Grouped display string for the custom amount field (wire:model). Livewire round-trips would strip Alpine masks,
     * so formatting is applied server-side after each sync.
     */
    public ?string $buyNowRequestedAmountInput = null;
    public ?int $buyNowCustomAmountMin = null;
    public ?int $buyNowCustomAmountMax = null;
    public int $buyNowCustomAmountStep = 1;
    /** @var int|string|null Livewire can send empty string while the user clears the quantity field. */
    public int|string|null $buyNowQuantity = 1;
    public array $buyNowRequirements = [];
    public array $buyNowRequirementsSchema = [];
    public array $buyNowRequirementsRules = [];
    public array $buyNowRequirementsAttributes = [];
    public ?string $buyNowError = null;
    public ?string $buyNowSuccess = null;
    public ?string $buyNowOrderNumber = null;
    public bool $buyNowNeedsFunds = false;
    public bool $isPackageOverlayOpen = false;
    public bool $showPackageProducts = false;
    public ?int $selectedPackageId = null;
    public ?string $selectedPackageName = null;
    public array $packageProducts = [];

    public ?float $buyNowLineFinalPrice = null;

    public ?string $buyNowPriceError = null;

    /**
     * Final line price ÷ amount at open (custom products). When client tier math is active, Alpine uses rules from buyNowClientPricingContext(); otherwise this rate drives the estimate.
     */
    public ?float $buyNowFinalPerUnitRate = null;

    /**
     * Live line totals for custom-amount rows in the package overlay (product id => quote).
     *
     * @var array<int, array{final: ?float, per_unit: ?float, error: ?string}>
     */
    public array $packageOverlayLivePrices = [];

    /**
     * @var array<int, array<string, mixed>>
     */
    public array $packageOverlayClientPricingContexts = [];

    public function mount(): void
    {
        if (! auth()->check()) {
            return;
        }

        $loginIntent = BuyNowLoginIntent::pull();
        if ($loginIntent !== null) {
            $this->restorePurchaseIntent($loginIntent);

            return;
        }

        $resumeIntent = PurchaseResumeIntent::peek();
        if ($resumeIntent !== null && ($resumeIntent['source'] ?? null) === PurchaseResumeIntent::SOURCE_BUY_NOW) {
            $this->restorePurchaseIntent($resumeIntent);
            if (session()->pull('purchase_resume_ready')) {
                $this->info(__('messages.purchase_resume_restored'));
            }
        }
    }

    /**
     * Persist current buy-now state and continue to wallet top-up (no duplicated checkout logic).
     */
    public function continueToTopup(?string $amount = null): void
    {
        if (! auth()->check() || $this->buyNowProductId === null) {
            return;
        }

        $shortfall = null;
        if (is_string($amount) && is_numeric($amount) && (float) $amount > 0) {
            $shortfall = number_format((float) $amount, 2, '.', '');
        } elseif (
            $this->buyNowLineFinalPrice !== null
            && $this->walletAvailableToSpendAmount !== null
        ) {
            $diff = max(0, (float) $this->buyNowLineFinalPrice - (float) $this->walletAvailableToSpendAmount);
            if ($diff > 0) {
                $shortfall = number_format($diff, 2, '.', '');
            }
        }

        PurchaseResumeIntent::store([
            'source' => PurchaseResumeIntent::SOURCE_BUY_NOW,
            'product_id' => $this->buyNowProductId,
            'package_id' => $this->selectedPackageId,
            'quantity' => is_numeric($this->buyNowQuantity) ? (int) $this->buyNowQuantity : 1,
            'requested_amount' => $this->buyNowAmountMode === ProductAmountMode::Custom->value
                && is_numeric($this->buyNowRequestedAmount)
                ? (int) $this->buyNowRequestedAmount
                : null,
            'requirements' => $this->buyNowRequirements,
        ]);

        $this->redirect(
            route('wallet.topup', array_filter(['amount' => $shortfall])),
            navigate: true,
        );
    }

    #[Computed]
    public function walletAvailableToSpendAmount(): ?float
    {
        if (! auth()->check()) {
            return null;
        }

        return (float) Wallet::forUser(auth()->user())->availableToSpend();
    }

    /**
     * Active pricing rules + user flags for Alpine instant tier math (custom buy-now only).
     *
     * @return array<string, mixed>|null
     */
    public function buyNowClientPricingContext(): ?array
    {
        if ($this->buyNowAmountMode !== ProductAmountMode::Custom->value || ! auth()->check() || $this->buyNowProductId === null) {
            return null;
        }

        $product = Product::query()
            ->select(['id', 'entry_price'])
            ->whereKey($this->buyNowProductId)
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            return null;
        }

        return BuyNowClientPricingContext::build(auth()->user(), $product);
    }

    public function openBuyNow(int $productId, bool $fromPackageOverlay = false, mixed $quantity = null, mixed $overlayCustomAmount = null): void
    {
        if (! auth()->check()) {
            $this->redirectToLoginWithBuyNowIntent([
                'product_id' => $productId,
                'package_id' => $fromPackageOverlay ? $this->selectedPackageId : null,
                'quantity' => $this->normalizeBuyNowOptionalPositiveInt($quantity),
                'requested_amount' => $this->normalizeBuyNowOptionalPositiveInt($overlayCustomAmount),
            ]);

            return;
        }

        $this->reset('buyNowError', 'buyNowSuccess', 'buyNowOrderNumber', 'buyNowNeedsFunds');
        $this->resetValidation();

        $product = $this->loadProductForBuyNow($productId);

        if ($product === null) {
            return;
        }

        $normalizedQuantity = $this->normalizeBuyNowOptionalPositiveInt($quantity);
        $normalizedOverlayAmount = $this->normalizeBuyNowOptionalPositiveInt($overlayCustomAmount);

        $this->buyNowProductId = $product['id'];
        $this->buyNowProductName = $product['name'] ?? null;
        $this->buyNowPackageName = $product['package_name'] ?? $this->selectedPackageName;
        $this->buyNowAmountMode = (string) ($product['amount_mode'] ?? ProductAmountMode::Fixed->value);
        $this->buyNowAmountUnitLabel = $product['amount_unit_label'] ?? null;
        $this->buyNowEntryPrice = isset($product['entry_price']) ? (float) $product['entry_price'] : null;
        $this->buyNowCustomAmountMin = isset($product['custom_amount_min']) ? (int) $product['custom_amount_min'] : null;
        $this->buyNowCustomAmountMax = isset($product['custom_amount_max']) ? (int) $product['custom_amount_max'] : null;
        $this->buyNowCustomAmountStep = max(1, (int) ($product['custom_amount_step'] ?? 1));

        if ($this->buyNowAmountMode === ProductAmountMode::Custom->value) {
            $initialAmount = (int) ($this->buyNowCustomAmountMin ?? 1);
            if ($normalizedOverlayAmount !== null) {
                $productForAmountCheck = Product::query()
                    ->select([
                        'id',
                        'entry_price',
                        'custom_amount_min',
                        'custom_amount_max',
                        'custom_amount_step',
                    ])
                    ->whereKey($productId)
                    ->where('is_active', true)
                    ->first();
                if ($productForAmountCheck !== null) {
                    try {
                        app(\App\Domain\Pricing\CustomAmountValidator::class)
                            ->validate($productForAmountCheck, $normalizedOverlayAmount, 'buyNowRequestedAmount');
                        $initialAmount = $normalizedOverlayAmount;
                    } catch (ValidationException) {
                        // Fallback to minimum/default when overlay amount is not valid.
                    }
                }
            }
            $this->buyNowRequestedAmount = $initialAmount;
            $this->buyNowRequestedAmountInput = $this->formatGroupedIntegerForDisplay($initialAmount);
        } else {
            $this->buyNowRequestedAmount = null;
            $this->buyNowRequestedAmountInput = null;
        }
        $this->buyNowQuantity = $this->buyNowAmountMode === ProductAmountMode::Custom->value
            ? 1
            : max(1, (int) ($normalizedQuantity ?? 1));
        $this->buyNowRequirementsSchema = $product['requirements_schema'] ?? [];
        $this->buyNowRequirementsRules = $product['requirements_rules'] ?? [];
        $this->buyNowRequirementsAttributes = $product['requirements_attributes'] ?? [];
        $this->buyNowRequirements = [];

        foreach ($this->buyNowRequirementsSchema as $requirement) {
            if (! empty($requirement['key'])) {
                $this->buyNowRequirements[$requirement['key']] = '';
            }
        }

        $this->showBuyNowModal = true;
        $this->showPackageProducts = false;
        $this->isPackageOverlayOpen = $fromPackageOverlay;

        if (! $fromPackageOverlay) {
            $this->selectedPackageId = null;
            $this->selectedPackageName = null;
            $this->packageProducts = [];
        }

        $this->buyNowPriceError = null;
        $this->seedBuyNowPricingOnOpen();
    }

    public function closeBuyNow(): void
    {
        $this->reset([
            'showBuyNowModal',
            'buyNowProductId',
            'buyNowProductName',
            'buyNowPackageName',
            'buyNowQuantity',
            'buyNowRequirements',
            'buyNowRequirementsSchema',
            'buyNowRequirementsRules',
            'buyNowRequirementsAttributes',
            'buyNowError',
            'buyNowSuccess',
            'buyNowOrderNumber',
            'buyNowNeedsFunds',
            'isPackageOverlayOpen',
            'showPackageProducts',
            'selectedPackageId',
            'selectedPackageName',
            'packageProducts',
            'buyNowLineFinalPrice',
            'buyNowPriceError',
            'buyNowFinalPerUnitRate',
            'buyNowEntryPrice',
            'packageOverlayLivePrices',
            'packageOverlayClientPricingContexts',
        ]);
        $this->resetValidation();
    }

    /**
     * Runs once when the buy-now sheet opens: loads final price (fixed qty) or per-unit rate at the default amount (custom).
     */
    public function seedBuyNowPricingOnOpen(): void
    {
        $this->buyNowPriceError = null;
        $this->buyNowLineFinalPrice = null;
        $this->buyNowFinalPerUnitRate = null;

        if (! auth()->check() || $this->buyNowProductId === null || $this->showPackageProducts) {
            return;
        }

        $product = Product::query()
            ->select([
                'id',
                'entry_price',
                'amount_mode',
                'custom_amount_min',
                'custom_amount_max',
                'custom_amount_step',
            ])
            ->whereKey($this->buyNowProductId)
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            return;
        }

        $pricingEngine = app(PricingEngine::class);
        $user = auth()->user();

        if ($this->buyNowAmountMode === ProductAmountMode::Custom->value) {
            try {
                $quote = $pricingEngine->quote($product, 1, (int) $this->buyNowRequestedAmount, $user);
                $this->buyNowLineFinalPrice = $quote->finalTotal;
                $this->buyNowFinalPerUnitRate = $quote->unitPrice;
            } catch (ValidationException $exception) {
                $this->buyNowLineFinalPrice = null;
                $this->buyNowFinalPerUnitRate = null;
                $this->buyNowPriceError = collect($exception->errors())->flatten()->first()
                    ?? __('messages.invalid_value', ['field' => __('messages.amount')]);
            } catch (\InvalidArgumentException $exception) {
                $this->buyNowLineFinalPrice = null;
                $this->buyNowFinalPerUnitRate = null;
                $this->buyNowPriceError = $this->buyNowPriceErrorFromAmountException(
                    $exception,
                    $product->entry_price !== null ? (float) $product->entry_price : null
                );
            }

            return;
        }

        $quote = $pricingEngine->quote($product, max(1, (int) $this->buyNowQuantity), null, $user);
        $this->buyNowLineFinalPrice = $quote->finalTotal;
    }

    public function refreshBuyNowLinePrice(): void
    {
        if ($this->buyNowAmountMode === ProductAmountMode::Custom->value) {
            return;
        }

        $this->buyNowPriceError = null;

        if (! auth()->check() || $this->buyNowProductId === null || $this->showPackageProducts) {
            $this->buyNowLineFinalPrice = null;

            return;
        }

        $userId = (string) (auth()->id() ?? request()->ip() ?? 'guest');
        $rateLimitKey = sprintf('buy-now-reprice:%s:%d', $userId, $this->buyNowProductId);
        $allowed = RateLimiter::attempt($rateLimitKey, 20, fn (): bool => true, 60);

        if (! $allowed) {
            $this->buyNowPriceError = __('messages.something_went_wrong_checkout');

            return;
        }

        $product = Product::query()
            ->select([
                'id',
                'entry_price',
                'amount_mode',
                'custom_amount_min',
                'custom_amount_max',
                'custom_amount_step',
            ])
            ->whereKey($this->buyNowProductId)
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            $this->buyNowLineFinalPrice = null;

            return;
        }

        if ($this->buyNowQuantity === '' || $this->buyNowQuantity === null) {
            $this->buyNowLineFinalPrice = null;

            return;
        }

        $pricingEngine = app(PricingEngine::class);
        $user = auth()->user();
        $quote = $pricingEngine->quote($product, max(1, (int) $this->buyNowQuantity), null, $user);
        $this->buyNowLineFinalPrice = $quote->finalTotal;
    }

    /**
     * Same flow as cart/dropdown: blur → validate amount → server quote (tiers apply to entry total).
     * Syncs Alpine rate via browser event (see x-on:buy-now-custom-amount-repriced.window).
     */
    public function repriceBuyNowCustomAmount(mixed $requestedAmount): void
    {
        $this->resetErrorBag('buyNowRequestedAmount');
        $this->buyNowPriceError = null;

        if (! auth()->check() || $this->buyNowProductId === null || $this->showPackageProducts) {
            return;
        }

        if ($this->buyNowAmountMode !== ProductAmountMode::Custom->value) {
            return;
        }

        $userId = (string) (auth()->id() ?? request()->ip() ?? 'guest');
        $rateLimitKey = sprintf('buy-now-reprice:%s:%d', $userId, $this->buyNowProductId);
        $allowed = RateLimiter::attempt($rateLimitKey, 20, fn (): bool => true, 60);

        if (! $allowed) {
            $this->buyNowPriceError = __('messages.something_went_wrong_checkout');
            $this->dispatch(
                'buy-now-custom-amount-repriced',
                rate: $this->buyNowFinalPerUnitRate,
                serverError: $this->buyNowPriceError,
                amountInputStr: $this->buyNowRequestedAmountInput,
                pricingContext: $this->buyNowClientPricingContext(),
            );

            return;
        }

        $product = Product::query()
            ->select([
                'id',
                'entry_price',
                'amount_mode',
                'custom_amount_min',
                'custom_amount_max',
                'custom_amount_step',
            ])
            ->whereKey($this->buyNowProductId)
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            return;
        }

        $validator = app(CustomAmountValidator::class);

        try {
            $amount = $validator->validate($product, $requestedAmount, 'buyNowRequestedAmount');
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->buyNowRequestedAmount = $amount;
        $this->buyNowRequestedAmountInput = $this->formatGroupedIntegerForDisplay($amount);

        $pricingEngine = app(PricingEngine::class);
        $user = auth()->user();

        try {
            $quote = $pricingEngine->quote($product, 1, $amount, $user);
            $this->buyNowLineFinalPrice = $quote->finalTotal;
            $this->buyNowFinalPerUnitRate = $quote->unitPrice;
            $this->buyNowPriceError = null;
        } catch (ValidationException $exception) {
            $this->buyNowLineFinalPrice = null;
            $this->buyNowFinalPerUnitRate = null;
            $this->buyNowPriceError = collect($exception->errors())->flatten()->first()
                ?? __('messages.invalid_value', ['field' => __('messages.amount')]);
        } catch (\InvalidArgumentException $exception) {
            $this->buyNowLineFinalPrice = null;
            $this->buyNowFinalPerUnitRate = null;
            $this->buyNowPriceError = $this->buyNowPriceErrorFromAmountException(
                $exception,
                $product->entry_price !== null ? (float) $product->entry_price : null
            );
        }

        $this->dispatch(
            'buy-now-custom-amount-repriced',
            rate: $this->buyNowFinalPerUnitRate,
            serverError: $this->buyNowPriceError,
            amountInputStr: $this->buyNowRequestedAmountInput,
            pricingContext: $this->buyNowClientPricingContext(),
        );
    }

    public function repriceCustomAmount(int $productId, mixed $requestedAmount): void
    {
        $result = app(\App\Actions\Cart\RepriceCustomAmountLinePrice::class)->handle(
            productId: $productId,
            requestedAmount: $requestedAmount,
            user: auth()->user(),
            rateLimiterIdentity: (string) (auth()->id() ?? request()->ip() ?? 'guest'),
        );

        if (($result['ok'] ?? false) !== true) {
            if (($result['silent'] ?? false) === true) {
                return;
            }

            $this->dispatch('cart-custom-amount-priced', productId: $productId, message: $result['message'] ?? null);

            return;
        }

        $this->dispatch(
            'cart-custom-amount-priced',
            productId: $productId,
            price: $result['price'],
            requestedAmount: $result['requested_amount'],
            message: null,
        );
    }

    public function updatedBuyNowQuantity(mixed $value): void
    {
        if ($this->buyNowAmountMode === ProductAmountMode::Custom->value) {
            $this->buyNowQuantity = 1;

            return;
        }

        if ($value === '' || $value === null) {
            $this->buyNowQuantity = '';
            $this->buyNowLineFinalPrice = null;
            $this->buyNowPriceError = null;
            $this->resetErrorBag('buyNowQuantity');

            return;
        }

        $this->buyNowQuantity = max(1, (int) $value);
        $this->validateOnly('buyNowQuantity', $this->buyNowRules(), [], $this->buyNowAttributes());
        $this->refreshBuyNowLinePrice();
    }

    public function updatedBuyNowRequirements(mixed $value, string $key): void
    {
        $this->validateOnly("buyNowRequirements.$key", $this->buyNowRules(), [], $this->buyNowAttributes());
    }

    public function submitBuyNow(?int $clientCustomAmount = null): void
    {
        $this->reset('buyNowError', 'buyNowSuccess', 'buyNowOrderNumber', 'buyNowNeedsFunds');

        if (! auth()->check()) {
            $this->redirectToLoginWithBuyNowIntent([
                'product_id' => $this->buyNowProductId,
                'package_id' => $this->selectedPackageId,
                'quantity' => $this->normalizeBuyNowOptionalPositiveInt($this->buyNowQuantity),
                'requested_amount' => $this->buyNowAmountMode === ProductAmountMode::Custom->value
                    ? $this->normalizeBuyNowOptionalPositiveInt($clientCustomAmount ?? $this->buyNowRequestedAmount)
                    : null,
            ]);

            return;
        }

        if ($this->buyNowProductId === null) {
            $this->buyNowError = __('messages.product_missing');
            return;
        }

        $product = $this->loadProductForBuyNow($this->buyNowProductId);

        if ($product === null) {
            $this->buyNowError = __('messages.product_missing');
            return;
        }

        if ($this->buyNowAmountMode === ProductAmountMode::Custom->value) {
            $this->buyNowQuantity = 1;
            if ($clientCustomAmount !== null) {
                $this->buyNowRequestedAmount = max(0, $clientCustomAmount);
                $this->buyNowRequestedAmountInput = $this->formatGroupedIntegerForDisplay((int) $this->buyNowRequestedAmount);
            } else {
                $this->syncBuyNowRequestedAmountFromInputString();
            }
            if ($this->buyNowCustomAmountStep > 1 && $this->buyNowRequestedAmount % $this->buyNowCustomAmountStep !== 0) {
                $this->addError('buyNowRequestedAmount', __('messages.invalid_value', ['field' => __('messages.amount')]));
                return;
            }
        } else {
            $this->buyNowQuantity = max(1, (int) $this->buyNowQuantity);
            $this->buyNowRequestedAmount = null;
        }
        $this->validate($this->buyNowRules(), [], $this->buyNowAttributes());

        try {
            $checkout = app(CheckoutFromPayload::class)->handle(
                auth()->user(),
                [[
                    'product_id' => $this->buyNowProductId,
                    'package_id' => $product['package_id'] ?? null,
                    'quantity' => $this->buyNowQuantity,
                    'requested_amount' => $this->buyNowAmountMode === ProductAmountMode::Custom->value
                        ? (int) $this->buyNowRequestedAmount
                        : null,
                    'requirements' => $this->buyNowRequirements,
                ]],
                [
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]
            );

            $order = $checkout->order;

            if (! $order->exists || $order->status !== OrderStatus::Paid) {
                $this->buyNowError = __('messages.checkout_could_not_complete');
                $this->error($this->buyNowError);

                return;
            }

            $message = $checkout->reusedExistingOrder
                ? __('messages.checkout_order_already_placed', ['order_number' => $order->order_number])
                : __('messages.payment_successful_order_processing', ['order_number' => $order->order_number]);
            $this->buyNowSuccess = $message;
            $this->buyNowOrderNumber = $order->order_number;
            $this->success($message);
            PurchaseResumeIntent::forget();
        } catch (ValidationException $exception) {
            $this->buyNowError = collect($exception->errors())->flatten()->first()
                ?? __('messages.checkout_validation_failed');
            $this->buyNowNeedsFunds = array_key_exists('wallet', $exception->errors());
            $this->error($this->buyNowError);
        } catch (\Throwable $e) {
            report($e);
            $this->buyNowError = __('messages.something_went_wrong_checkout');
            $this->error($this->buyNowError);
        }
    }

    public function openPackageOverlay(int $packageId): void
    {
        $this->reset('buyNowError', 'buyNowSuccess', 'buyNowOrderNumber');
        $this->resetValidation();
        $this->resetBuyNowState();
        $package = Package::query()
            ->select(['id', 'name'])
            ->with(['products' => function ($query): void {
                $query->select([
                    'id',
                    'package_id',
                    'name',
                    'slug',
                    'entry_price',
                    'retail_price',
                    'order',
                    'is_active',
                    'amount_mode',
                    'amount_unit_label',
                    'custom_amount_min',
                    'custom_amount_max',
                    'custom_amount_step',
                ])
                    ->with([
                        'package:id,name,image,is_active',
                        'package.requirements:id,package_id,key,label,type,is_required,validation_rules,order',
                    ])
                    ->where('is_active', true)
                    ->orderBy('order')
                    ->orderBy('name');
            }])
            ->whereKey($packageId)
            ->where('is_active', true)
            ->first();

        if ($package === null) {
            return;
        }

        $placeholderImage = asset('images/promotions/promo-placeholder.svg');
        $resolver = app(ResolvePackageRequirements::class);
        $pricingEngine = app(PricingEngine::class);
        $user = auth()->user();

        $this->packageProducts = $package->products
            ->map(fn (Product $product): array => $this->mapProduct($product, $resolver, $pricingEngine, $user, $placeholderImage))
            ->all();
        $this->selectedPackageId = $package->id;
        $this->selectedPackageName = $package->name;
        $this->isPackageOverlayOpen = true;
        $this->showPackageProducts = true;
        $this->showBuyNowModal = true;

        $this->seedPackageOverlayLivePrices();
    }

    public function backToPackageProducts(): void
    {
        if (! $this->isPackageOverlayOpen) {
            return;
        }

        $this->reset('buyNowError', 'buyNowSuccess', 'buyNowOrderNumber');
        $this->resetValidation();
        $this->resetBuyNowState();
        $this->showPackageProducts = true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    private function buyNowRules(): array
    {
        $rules = [
            'buyNowQuantity' => ['required', 'integer', 'min:1'],
        ];

        if ($this->buyNowAmountMode === ProductAmountMode::Custom->value) {
            $rules['buyNowRequestedAmount'] = array_values(array_filter([
                'required',
                'integer',
                'min:1',
                $this->buyNowCustomAmountMin !== null ? 'min:'.$this->buyNowCustomAmountMin : null,
                $this->buyNowCustomAmountMax !== null ? 'max:'.$this->buyNowCustomAmountMax : null,
            ]));
        }

        foreach ($this->buyNowRequirementsRules as $key => $ruleSet) {
            $rules["buyNowRequirements.$key"] = $ruleSet;
        }

        return $rules;
    }

    /**
     * @return array<string, string>
     */
    private function buyNowAttributes(): array
    {
        $attributes = [
            'buyNowQuantity' => __('messages.quantity'),
            'buyNowRequestedAmount' => __('messages.amount'),
        ];

        foreach ($this->buyNowRequirementsAttributes as $key => $label) {
            $attributes["buyNowRequirements.$key"] = $label;
        }

        return $attributes;
    }

    public function getBuyNowCanSubmitProperty(): bool
    {
        if ($this->showPackageProducts) {
            return false;
        }

        if ($this->buyNowProductId === null || $this->buyNowSuccess) {
            return false;
        }

        if ($this->buyNowAmountMode === ProductAmountMode::Custom->value) {
            if ($this->buyNowPriceError !== null) {
                return false;
            }
            $ctx = $this->buyNowClientPricingContext();
            $clientPricable = (bool) ($ctx['client_pricable'] ?? false);
            if (! $clientPricable && $this->buyNowFinalPerUnitRate === null) {
                return false;
            }
        } elseif ((int) $this->buyNowQuantity < 1) {
            return false;
        }

        if ($this->hasBuyNowErrors()) {
            return false;
        }

        foreach ($this->buyNowRequirementsSchema as $requirement) {
            if (empty($requirement['is_required']) || empty($requirement['key'])) {
                continue;
            }

            $value = $this->buyNowRequirements[$requirement['key']] ?? null;

            if (blank($value)) {
                return false;
            }
        }

        return true;
    }

    private function hasBuyNowErrors(): bool
    {
        foreach ($this->getErrorBag()->keys() as $key) {
            if ($key === 'buyNowQuantity' || $key === 'buyNowRequestedAmount' || str_starts_with($key, 'buyNowRequirements.')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array{product_id?: int|null, package_id?: int|null, quantity?: int|null, requested_amount?: int|null}  $intent
     */
    /**
     * @param  array{
     *     product_id?: int,
     *     package_id?: int,
     *     quantity?: int,
     *     requested_amount?: int,
     *     requirements?: array<string, string>
     * }  $intent
     */
    private function restorePurchaseIntent(array $intent): void
    {
        if (isset($intent['package_id'])) {
            $this->openPackageOverlay((int) $intent['package_id']);
        }

        if (! isset($intent['product_id'])) {
            return;
        }

        $this->openBuyNow(
            (int) $intent['product_id'],
            isset($intent['package_id']),
            $intent['quantity'] ?? null,
            $intent['requested_amount'] ?? null,
        );

        $requirements = $intent['requirements'] ?? null;
        if (! is_array($requirements) || $requirements === []) {
            return;
        }

        foreach ($requirements as $key => $value) {
            if (! is_string($key) || $key === '' || ! array_key_exists($key, $this->buyNowRequirements)) {
                continue;
            }
            $this->buyNowRequirements[$key] = is_scalar($value) ? (string) $value : '';
        }
    }

    private function redirectToLoginWithBuyNowIntent(array $intent): void
    {
        BuyNowLoginIntent::store([
            'product_id' => $intent['product_id'] ?? null,
            'package_id' => $intent['package_id'] ?? null,
            'quantity' => $intent['quantity'] ?? null,
            'requested_amount' => $intent['requested_amount'] ?? null,
        ]);

        $this->redirectRoute('login');
    }

    private function formatGroupedIntegerForDisplay(int $value): string
    {
        $localeTag = str_replace('_', '-', app()->getLocale());
        $englishStyle = str_starts_with($localeTag, 'en');

        return number_format($value, 0, $englishStyle ? '.' : ',', $englishStyle ? ',' : '.');
    }

    private function syncBuyNowRequestedAmountFromInputString(): void
    {
        $digits = preg_replace('/\D+/', '', (string) ($this->buyNowRequestedAmountInput ?? ''));
        $this->buyNowRequestedAmount = $digits === '' ? 0 : (int) $digits;
        $this->buyNowRequestedAmountInput = $this->formatGroupedIntegerForDisplay((int) $this->buyNowRequestedAmount);
    }

    private function buyNowPriceErrorFromAmountException(\InvalidArgumentException $exception, ?float $entryPrice): string
    {
        $message = $exception->getMessage();

        if ($entryPrice === null || $entryPrice <= 0) {
            return __('messages.invalid_value', ['field' => __('messages.entry_price')]);
        }

        if (str_contains($message, 'No active pricing rule') || str_contains($message, 'pricing rule matches')) {
            return __('messages.custom_amount_no_pricing_rule');
        }

        return __('messages.invalid_value', ['field' => __('messages.entry_price')]);
    }

    /**
     * Coerce Livewire/Alpine payloads (undefined, "", non-numeric) for optional positive integers.
     */
    private function normalizeBuyNowOptionalPositiveInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }

        if (is_string($value) && ! is_numeric($value)) {
            return null;
        }

        if (! is_numeric($value)) {
            return null;
        }

        $int = (int) $value;

        return $int >= 1 ? $int : null;
    }

    private function resetBuyNowState(): void
    {
        $this->buyNowProductId = null;
        $this->buyNowProductName = null;
        $this->buyNowPackageName = null;
        $this->buyNowAmountMode = ProductAmountMode::Fixed->value;
        $this->buyNowAmountUnitLabel = null;
        $this->buyNowRequestedAmount = null;
        $this->buyNowRequestedAmountInput = null;
        $this->buyNowCustomAmountMin = null;
        $this->buyNowCustomAmountMax = null;
        $this->buyNowCustomAmountStep = 1;
        $this->buyNowQuantity = 1;
        $this->buyNowRequirements = [];
        $this->buyNowRequirementsSchema = [];
        $this->buyNowRequirementsRules = [];
        $this->buyNowRequirementsAttributes = [];
        $this->buyNowLineFinalPrice = null;
        $this->buyNowPriceError = null;
        $this->buyNowFinalPerUnitRate = null;
        $this->buyNowEntryPrice = null;
        $this->packageOverlayLivePrices = [];
        $this->packageOverlayClientPricingContexts = [];
    }

    private function seedPackageOverlayLivePrices(): void
    {
        $this->packageOverlayLivePrices = [];
        $this->packageOverlayClientPricingContexts = [];

        foreach ($this->packageProducts as $row) {
            if (($row['amount_mode'] ?? '') !== ProductAmountMode::Custom->value) {
                continue;
            }

            $productId = (int) $row['id'];

            if (auth()->check()) {
                $productForCtx = Product::query()
                    ->select(['id', 'entry_price'])
                    ->whereKey($productId)
                    ->where('is_active', true)
                    ->first();
                if ($productForCtx !== null) {
                    $this->packageOverlayClientPricingContexts[$productId] = BuyNowClientPricingContext::build(auth()->user(), $productForCtx);
                }
            }

            $defaultAmount = (int) ($row['custom_amount_min'] ?? $row['custom_amount_step'] ?? 1);
            $this->quotePackageProductPrice($productId, $defaultAmount, false);
        }
    }

    private function quotePackageProductPrice(int $productId, int $amount, bool $withRateLimit): void
    {
        if ($withRateLimit) {
            $userId = (string) (auth()->id() ?? request()->ip() ?? 'guest');
            $allowed = RateLimiter::attempt(
                sprintf('buy-now-overlay-reprice:%s:%d', $userId, $productId),
                20,
                fn (): bool => true,
                60
            );

            if (! $allowed) {
                $this->packageOverlayLivePrices[$productId] = [
                    'final' => null,
                    'per_unit' => null,
                    'error' => __('messages.something_went_wrong_checkout'),
                ];

                return;
            }
        }

        $product = Product::query()
            ->select([
                'id',
                'entry_price',
                'amount_mode',
                'custom_amount_min',
                'custom_amount_max',
                'custom_amount_step',
            ])
            ->whereKey($productId)
            ->where('is_active', true)
            ->first();

        if ($product === null || ($product->amount_mode ?? ProductAmountMode::Fixed) !== ProductAmountMode::Custom) {
            $this->packageOverlayLivePrices[$productId] = [
                'final' => null,
                'per_unit' => null,
                'error' => null,
            ];

            return;
        }

        $pricingEngine = app(PricingEngine::class);
        $user = auth()->user();

        try {
            $quote = $pricingEngine->quote($product, 1, $amount, $user);
            $this->packageOverlayLivePrices[$productId] = [
                'final' => $quote->finalTotal,
                'per_unit' => $quote->unitPrice,
                'error' => null,
            ];
        } catch (ValidationException $exception) {
            $this->packageOverlayLivePrices[$productId] = [
                'final' => null,
                'per_unit' => null,
                'error' => collect($exception->errors())->flatten()->first()
                    ?? __('messages.invalid_value', ['field' => __('messages.amount')]),
            ];
        } catch (\InvalidArgumentException) {
            $this->packageOverlayLivePrices[$productId] = [
                'final' => null,
                'per_unit' => null,
                'error' => __('messages.invalid_value', ['field' => __('messages.entry_price')]),
            ];
        }
    }

    /**
     * @return array{
     *   id: int,
     *   package_id: int|null,
     *   package_name: string|null,
     *   name: string,
     *   price: mixed,
     *   href: string,
     *   image: string,
     *   requirements_schema: array<int, array<string, mixed>>,
     *   requirements_rules: array<string, array<int, string>>,
     *   requirements_attributes: array<string, string>
     * }|null
     */
    private function loadProductForBuyNow(int $productId): ?array
    {
        $product = Product::query()
            ->select([
                'id',
                'package_id',
                'name',
                'slug',
                'entry_price',
                'retail_price',
                'order',
                'is_active',
                'amount_mode',
                'amount_unit_label',
                'custom_amount_min',
                'custom_amount_max',
                'custom_amount_step',
            ])
            ->with([
                'package:id,name,image,is_active',
                'package.requirements:id,package_id,key,label,type,is_required,validation_rules,order',
            ])
            ->whereKey($productId)
            ->where('is_active', true)
            ->first();

        if ($product === null) {
            return null;
        }

        $placeholderImage = asset('images/promotions/promo-placeholder.svg');
        $resolver = app(ResolvePackageRequirements::class);
        $pricingEngine = app(PricingEngine::class);
        $user = auth()->user();

        return $this->mapProduct($product, $resolver, $pricingEngine, $user, $placeholderImage);
    }

    private function mapProduct(Product $product, ResolvePackageRequirements $resolver, PricingEngine $pricingEngine, ?\App\Models\User $user, string $placeholderImage): array
    {
        $resolved = $resolver->handle($product->package?->requirements ?? collect());
        $defaultAmount = ($product->amount_mode ?? ProductAmountMode::Fixed) === ProductAmountMode::Custom
            ? (int) ($product->custom_amount_min ?? $product->custom_amount_step ?? 1)
            : null;

        try {
            $quote = $pricingEngine->quote($product, 1, $defaultAmount, $user);
        } catch (ValidationException|\InvalidArgumentException) {
            $quote = new \App\Domain\Pricing\PriceQuoteDTO(
                amountMode: ($product->amount_mode ?? ProductAmountMode::Fixed)->value,
                basePrice: 0.0,
                discountAmount: 0.0,
                finalPrice: 0.0,
                finalTotal: 0.0,
                unitPrice: 0.0,
                quantity: 1,
                requestedAmount: $defaultAmount,
                tierName: null,
                meta: [],
            );
        }

        return [
            'id' => $product->id,
            'package_id' => $product->package_id,
            'package_name' => $product->package?->name,
            'name' => $product->name,
            'entry_price' => $product->entry_price !== null ? (float) $product->entry_price : null,
            'price' => $quote->finalTotal,
            'base_price' => $quote->basePrice,
            'discount_amount' => $quote->discountAmount,
            'tier_name' => $quote->tierName,
            'amount_mode' => ($product->amount_mode ?? ProductAmountMode::Fixed)->value,
            'amount_unit_label' => $product->amount_unit_label,
            'custom_amount_min' => $product->custom_amount_min,
            'custom_amount_max' => $product->custom_amount_max,
            'custom_amount_step' => $product->custom_amount_step,
            'href' => '#',
            'image' => filled($product->package?->image)
                ? asset($product->package->image)
                : $placeholderImage,
            'requirements_schema' => $resolved['schema'],
            'requirements_rules' => $resolved['rules'],
            'requirements_attributes' => $resolved['attributes'],
        ];
    }

};
?>

<flux:modal

    wire:model.self="showBuyNowModal"
    variant="floating"
    class="max-w-3xl"
    @close="closeBuyNow"
    @cancel="closeBuyNow"
>
    @php
        $bnLocaleTag = str_replace('_', '-', app()->getLocale());
        $bnHtmlDir = app()->getLocale() === 'ar' ? 'rtl' : 'ltr';
        $bnNumericDir = 'ltr';
        $bnAmountMaskEn = str_starts_with($bnLocaleTag, 'en');
        $bnMaskDec = $bnAmountMaskEn ? '.' : ',';
        $bnMaskThousands = $bnAmountMaskEn ? ',' : '.';
        $bnEstimateStep = max(1, (int) ($buyNowCustomAmountStep ?? 1));
        $buyNowClientPricingContext = $this->buyNowClientPricingContext();
        $buyNowCustomEstimateConfig = [
            'amountInputStr' => $buyNowRequestedAmountInput ?? '',
            'rate' => $buyNowFinalPerUnitRate,
            'pricingContext' => $buyNowClientPricingContext,
            'serverError' => $buyNowPriceError,
            'amountMin' => $buyNowCustomAmountMin,
            'amountMax' => $buyNowCustomAmountMax,
            'amountStep' => $buyNowCustomAmountStep,
            'maskDec' => $bnMaskDec,
            'maskThousands' => $bnMaskThousands,
            'messages' => [
                'enter_amount' => __('messages.buy_now_estimate_enter_amount'),
                'below_min' => __('messages.buy_now_estimate_below_min', ['min' => $buyNowCustomAmountMin ?? 0]),
                'above_max' => $buyNowCustomAmountMax !== null
                    ? __('messages.buy_now_estimate_above_max', ['max' => $buyNowCustomAmountMax])
                    : '',
                'bad_step' => __('messages.buy_now_estimate_bad_step', [
                    'step' => $bnEstimateStep,
                    'step2' => $bnEstimateStep * 2,
                    'step3' => $bnEstimateStep * 3,
                ]),
                'price_unavailable' => __('messages.buy_now_estimate_price_unavailable'),
            ],
        ];
    @endphp
    <div
        class="relative space-y-4"
        wire:key="buy-now-shell-{{ (string) ($buyNowProductId ?? '0') }}-{{ $buyNowAmountMode }}"
        @if ($buyNowAmountMode === \App\Enums\ProductAmountMode::Custom->value)
            x-data="buyNowCustomAmountEstimate({!! \Illuminate\Support\Js::from($buyNowCustomEstimateConfig)->toHtml() !!})"
            x-on:buy-now-custom-amount-repriced.window="
                if ($event.detail.rate !== undefined) { rate = $event.detail.rate; }
                if ($event.detail.serverError !== undefined) { serverError = $event.detail.serverError; }
                if ($event.detail.amountInputStr !== undefined && $event.detail.amountInputStr !== null) { amountInputStr = $event.detail.amountInputStr; }
                if ($event.detail.pricingContext !== undefined) { pricingContext = $event.detail.pricingContext; }
            "
        @else
            x-data
        @endif
        x-on:open-buy-now.window="$wire.openBuyNow($event.detail.productId, false, $event.detail.quantity === undefined ? null : $event.detail.quantity)"
        x-on:open-package-overlay.window="$wire.openPackageOverlay($event.detail.packageId)"
    >
        <div class="flex items-center">
            <div class="flex items-center">
                @if ($isPackageOverlayOpen && ! $showPackageProducts)
                    <flux:button variant="ghost" size="xs" wire:click="backToPackageProducts">
                        <span class="flex items-center gap-1">
                            <flux:icon icon="chevron-left" class="size-4 rtl:rotate-180" />
                            {{ __('messages.back') }}
                        </span>
                    </flux:button>
                @endif
            </div>
            <div class="flex flex-col flex-1 min-w-0 pe-12 items-center justify-center gap-0.5 text-center">
                @php
                    $packageLabel = $selectedPackageName ?? $buyNowPackageName;
                @endphp
                @if ($packageLabel)
                    <span class="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-100/80 px-4 py-2 text-sm font-semibold text-zinc-900 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100">
                        <span class="truncate">{{ $packageLabel }}</span>
                        @if (!$showPackageProducts)
                            <span class="shrink-0 text-zinc-500 dark:text-zinc-400 rtl:rotate-180" aria-hidden="true">→</span>
                            <span class="truncate font-medium text-zinc-700 dark:text-zinc-300">{{ $buyNowProductName ?? __('main.buy_now') }}</span>
                        @endif
                    </span>
                @endif
            </div>
            <div class="flex justify-end">
{{--                <flux:button--}}
{{--                    variant="ghost"--}}
{{--                    size="xs"--}}
{{--                    icon="x-mark"--}}
{{--                    wire:click="closeBuyNow"--}}
{{--                    aria-label="{{ __('messages.cancel') }}"--}}
{{--                />--}}
            </div>
        </div>

        @if ($showPackageProducts)
            <div class="space-y-4" wire:loading.class="opacity-60" wire:target="openPackageOverlay">
            <div wire:loading.flex wire:target="openPackageOverlay" class="items-center justify-center gap-2 py-8 text-sm text-zinc-500 dark:text-zinc-400">
                <flux:icon icon="arrow-path" class="size-5 animate-spin" />
                <span>{{ __('messages.please_wait') }}</span>
            </div>
            <div wire:loading.remove wire:target="openPackageOverlay">
            @if ($packageProducts === [])
                <flux:callout variant="subtle" icon="information-circle">
                    {{ __('messages.no_products_yet') }}
                </flux:callout>
            @else
                <div class="space-y-3">
                    @foreach ($packageProducts as $product)
                        @php
                            $overlayPid = (int) $product['id'];
                            $isOverlayCustom = ($product['amount_mode'] ?? '') === \App\Enums\ProductAmountMode::Custom->value;
                            $overlayQuote = $packageOverlayLivePrices[$overlayPid] ?? [];
                            $overlayError = $overlayQuote['error'] ?? null;
                            $overlayFinal = $overlayQuote['final'] ?? null;
                            $overlayPerUnit = $overlayQuote['per_unit'] ?? null;
                        @endphp
                        @if ($isOverlayCustom)
                            @php
                                $overlayRowStep = max(1, (int) ($product['custom_amount_step'] ?? 1));
                                $overlayRowEstimateConfig = [
                                    'rate' => $overlayPerUnit !== null ? (float) $overlayPerUnit : null,
                                    'pricingContext' => $packageOverlayClientPricingContexts[$overlayPid] ?? null,
                                    'serverError' => $overlayError,
                                    'amountMin' => $product['custom_amount_min'] ?? null,
                                    'amountMax' => $product['custom_amount_max'] ?? null,
                                    'amountStep' => $overlayRowStep,
                                    'maskDec' => $bnMaskDec,
                                    'maskThousands' => $bnMaskThousands,
                                    'initialAmount' => (int) ($product['custom_amount_min'] ?? $product['custom_amount_step'] ?? 1),
                                    'messages' => [
                                        'enter_amount' => __('messages.buy_now_estimate_enter_amount'),
                                        'below_min' => __('messages.buy_now_estimate_below_min', ['min' => $product['custom_amount_min'] ?? 0]),
                                        'above_max' => ($product['custom_amount_max'] ?? null) !== null
                                            ? __('messages.buy_now_estimate_above_max', ['max' => $product['custom_amount_max']])
                                            : '',
                                        'bad_step' => __('messages.buy_now_estimate_bad_step', [
                                            'step' => $overlayRowStep,
                                            'step2' => $overlayRowStep * 2,
                                            'step3' => $overlayRowStep * 3,
                                        ]),
                                        'price_unavailable' => __('messages.buy_now_estimate_price_unavailable'),
                                    ],
                                    'product' => $product,
                                ];
                            @endphp
                            <div
                                x-data="packageOverlayCustomAmountRow({!! \Illuminate\Support\Js::from($overlayRowEstimateConfig)->toHtml() !!})"
                                class="flex flex-col gap-4 rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-900 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                                wire:key="package-product-{{ $product['id'] }}"
                            >
                                <div class="text-base font-semibold leading-snug text-zinc-900 dark:text-zinc-100">
                                    {{ $product['name'] }}
                                </div>
                                <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:gap-6">
                                    <div class="min-w-0 w-full max-w-xs space-y-1">
                                        <label class="block text-xs font-medium text-zinc-600 dark:text-zinc-400" for="package-overlay-amount-{{ $overlayPid }}">
                                            {{ __('messages.amount') }}
                                        </label>
                                        <input
                                            id="package-overlay-amount-{{ $overlayPid }}"
                                            type="text"
                                            inputmode="numeric"
                                            dir="{{ $bnHtmlDir }}"
                                            class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm outline-none transition focus:border-(--color-accent) dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                                            x-mask:dynamic="$money($input, '{{ $bnMaskDec }}', '{{ $bnMaskThousands }}', 0)"
                                            x-model="amountInputStr"
                                        />
                                        <p class="text-[11px] leading-relaxed text-zinc-500 dark:text-zinc-400">
                                            {{ ($product['custom_amount_min'] ?? '—') . ' - ' . ($product['custom_amount_max'] ?? '—') . ' (' . ($product['custom_amount_step'] ?? 1) . ' step)' }}
                                            @if (! empty($product['amount_unit_label']))
                                                <span class="ms-1">{{ $product['amount_unit_label'] }}</span>
                                            @endif
                                        </p>
                                    </div>
                                    @if(\App\Models\WebsiteSetting::getPricesVisible())
                                        <div
                                            class="flex w-full min-w-0 flex-1 gap-3 rounded-xl border px-4 py-3 transition-[box-shadow,border-color,background-color] duration-200 lg:max-w-sm"
                                            x-bind:class="{
                                                'border-zinc-200 bg-zinc-50/80 dark:border-zinc-700 dark:bg-zinc-800/50': estimateCardEmphasis === 'neutral' || estimateCardEmphasis === 'success',
                                                'border-rose-400/90 bg-gradient-to-br from-rose-50 via-white to-rose-50/80 shadow-md shadow-rose-200/40 ring-2 ring-rose-300/70 dark:border-rose-700 dark:from-rose-950/50 dark:via-zinc-900/60 dark:to-rose-950/30 dark:shadow-rose-950/30 dark:ring-rose-800/60': estimateCardEmphasis === 'attention',
                                                'border-red-400/90 bg-gradient-to-br from-red-50 via-white to-red-50/80 shadow-md shadow-red-200/40 ring-2 ring-red-300/70 dark:border-red-800 dark:from-red-950/45 dark:via-zinc-900/60 dark:to-red-950/30 dark:shadow-red-950/30 dark:ring-red-900/50': estimateCardEmphasis === 'error',
                                            }"
                                        >
                                            <span
                                                class="mt-0.5 shrink-0"
                                                x-show="estimateCardEmphasis === 'attention'"
                                                x-cloak
                                                aria-hidden="true"
                                            >
                                                <flux:icon icon="exclamation-triangle" class="size-5 text-rose-600 dark:text-rose-400" />
                                            </span>
                                            <span
                                                class="mt-0.5 shrink-0"
                                                x-show="estimateCardEmphasis === 'error'"
                                                x-cloak
                                                aria-hidden="true"
                                            >
                                                <flux:icon icon="exclamation-circle" class="size-5 text-red-600 dark:text-red-400" />
                                            </span>
                                            <div class="min-w-0 flex-1 space-y-3">
                                                <div>
                                                    <div
                                                        class="text-[10px] font-semibold uppercase tracking-wide"
                                                        x-bind:class="{
                                                            'text-zinc-500 dark:text-zinc-400': estimateCardEmphasis === 'neutral' || estimateCardEmphasis === 'success',
                                                            'text-rose-800 dark:text-rose-200': estimateCardEmphasis === 'attention',
                                                            'text-red-800 dark:text-red-200': estimateCardEmphasis === 'error',
                                                        }"
                                                    >
                                                        {{ __('messages.unit_price') }}
                                                    </div>
                                                    <div class="mt-0.5 tabular-nums text-sm font-semibold text-zinc-900 dark:text-zinc-100" dir="{{ $bnNumericDir }}">
                                                        <span
                                                            x-show="! serverError && payableRate !== null && payableRate !== undefined"
                                                            class="inline-flex flex-wrap items-baseline gap-x-1"
                                                        >
                                                            <span x-text="formatPerUnitRate(payableRate)"></span>
                                                            @if (! empty($product['amount_unit_label']))
                                                                <span class="text-xs font-normal text-zinc-500 dark:text-zinc-400">/ {{ $product['amount_unit_label'] }}</span>
                                                            @endif
                                                        </span>
                                                        <span
                                                            x-show="serverError || payableRate === null || payableRate === undefined"
                                                            class="text-sm font-normal text-zinc-500 dark:text-zinc-400"
                                                        >—</span>
                                                    </div>
                                                </div>
                                                <div>
                                                    <div
                                                        class="text-[10px] font-semibold uppercase tracking-wide"
                                                        x-bind:class="{
                                                            'text-zinc-500 dark:text-zinc-400': estimateCardEmphasis === 'neutral' || estimateCardEmphasis === 'success',
                                                            'text-rose-800 dark:text-rose-200': estimateCardEmphasis === 'attention',
                                                            'text-red-800 dark:text-red-200': estimateCardEmphasis === 'error',
                                                        }"
                                                    >
                                                        {{ __('messages.estimated_total') }}
                                                    </div>
                                                    <div class="mt-1 min-h-[2rem] tabular-nums text-xl font-bold text-(--color-accent)" dir="{{ $bnNumericDir }}">
                                                        <span
                                                            x-show="serverError"
                                                            x-text="serverError"
                                                            class="block text-sm font-semibold text-red-700 dark:text-red-300"
                                                        ></span>
                                                        <span
                                                            x-show="! serverError && estimatedFinal !== null"
                                                            x-text="formatMoney(estimatedFinal)"
                                                            class="block"
                                                        ></span>
                                                        <span
                                                            x-show="! serverError && estimatedFinal === null"
                                                            x-text="estimateHintText"
                                                            class="block text-sm leading-snug"
                                                            x-bind:class="{
                                                                'font-normal text-zinc-500 dark:text-zinc-400': estimateCardEmphasis === 'neutral',
                                                                'font-semibold text-rose-800 dark:text-rose-200': estimateCardEmphasis === 'attention',
                                                            }"
                                                        ></span>
                                                    </div>
                                                </div>
                                                <p
                                                    class="text-[10px] leading-relaxed"
                                                    x-bind:class="{
                                                        'text-zinc-500 dark:text-zinc-400': estimateCardEmphasis === 'neutral' || estimateCardEmphasis === 'success',
                                                        'text-rose-700/90 dark:text-rose-300/90': estimateCardEmphasis === 'attention',
                                                        'text-red-700/85 dark:text-red-300/90': estimateCardEmphasis === 'error',
                                                    }"
                                                >
                                                    {{ __('messages.live_price_checkout_hint') }}
                                                </p>
                                            </div>
                                        </div>
                                    @endif
                                    <div class="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:flex-col lg:items-stretch">
                                        <flux:button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="min-h-11 flex-1 lg:flex-none"
                                            x-on:click="
                                                $store.cart.add(product, qty);
                                                const item = $store.cart.items.find((entry) => entry.id === product.id);
                                                if (item && digitsParsed > 0) {
                                                    item.quantity = 1;
                                                    item.requested_amount = digitsParsed;
                                                    item.requested_amount_input = $store.cart.formatGroupedIntegerForAmount(digitsParsed);
                                                    delete $store.cart.customAmountErrors[product.id];
                                                    if (estimatedFinal !== null) {
                                                        item.price = Number(estimatedFinal);
                                                        if (digitsParsed > 0) {
                                                            item.custom_unit_rate = Math.round((Number(estimatedFinal) / digitsParsed) * 1e8) / 1e8;
                                                        }
                                                    }
                                                    $store.cart.persist();
                                                    $wire.repriceCustomAmount(product.id, digitsParsed);
                                                }
                                                qty = 1;
                                            "
                                        >
                                            {{ __('main.add_to_cart') }}
                                        </flux:button>
                                        <flux:button
                                            type="button"
                                            variant="primary"
                                            size="sm"
                                            class="min-h-11 flex-1 lg:flex-none"
                                            x-on:click="$wire.openBuyNow({{ $product['id'] }}, true, 1, digitsParsed)"
                                        >
                                            {{ __('main.buy_now') }}
                                        </flux:button>
                                    </div>
                                </div>
                            </div>
                        @else
                            <div
                                x-data="{ product: @js($product), qty: 1 }"
                                class="flex flex-col gap-3 rounded-xl border border-zinc-200 bg-white p-3 text-sm text-zinc-900 shadow-sm dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 sm:flex-row sm:items-center sm:justify-between"
                                wire:key="package-product-{{ $product['id'] }}"
                            >
                                <div class="flex flex-1 justify-between gap-3 space-y-1 text-start">
                                    <div class="font-semibold">
                                        {{ $product['name'] }}
                                    </div>
                                    @if(\App\Models\WebsiteSetting::getPricesVisible())
                                        <div class="tabular-nums text-lg font-semibold text-(--color-accent)" dir="{{ $bnNumericDir }}">
                                            <span x-text="$store.cart.format({{ (float) $product['price'] }})">${{ number_format((float) $product['price'], 2) }}</span>
                                        </div>
                                    @endif
                                </div>

                                <div class="flex flex-wrap items-center justify-between gap-3 sm:justify-end">
                                    <div class="inline-flex items-center gap-1 rounded-lg border border-zinc-200 bg-white px-1 py-0.5 text-xs font-semibold text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                                        <button
                                            type="button"
                                            class="flex size-7 items-center justify-center rounded-md hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                            x-on:click="qty = Math.max(1, qty - 1)"
                                            aria-label="{{ __('main.decrease') }}"
                                        >
                                            <flux:icon icon="minus" class="size-3" />
                                        </button>
                                        <span class="min-w-6 text-center text-sm" x-text="qty"></span>
                                        <button
                                            type="button"
                                            class="flex size-7 items-center justify-center rounded-md hover:bg-zinc-100 dark:hover:bg-zinc-800"
                                            x-on:click="qty += 1"
                                            aria-label="{{ __('main.increase') }}"
                                        >
                                            <flux:icon icon="plus" class="size-3" />
                                        </button>
                                    </div>

                                    <div class="flex w-full items-center gap-2 sm:w-auto">
                                        <flux:button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            class="min-h-11 flex-1 sm:flex-none"
                                            x-on:click="$store.cart.add(product, qty); qty = 1;"
                                        >
                                            {{ __('main.add_to_cart') }}
                                        </flux:button>
                                        <flux:button
                                            type="button"
                                            variant="primary"
                                            size="sm"
                                            class="min-h-11 flex-1 sm:flex-none"
                                            x-on:click="$wire.openBuyNow({{ $product['id'] }}, true, Math.max(1, parseInt(qty, 10) || 1), null)"
                                        >
                                            {{ __('main.buy_now') }}
                                        </flux:button>
                                    </div>
                                </div>
                            </div>
                        @endif
                    @endforeach
                </div>
            @endif

            <div class="grid gap-3 sm:grid-cols-2">
                <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.description') }}
                    </div>
                    <div class="mt-2">{{ __('messages.package_description_placeholder') }}</div>
                </div>
                <div class="rounded-xl border border-zinc-200 bg-white p-4 text-sm text-zinc-600 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300">
                    <div class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.details') }}
                    </div>
                    <div class="mt-2">{{ __('messages.packages_intro') }}</div>
                </div>
            </div>
            </div>{{-- wire:loading.remove --}}
            </div>{{-- package products shell --}}
        @else
            {{-- Buy Now form: scroll body + sticky thumb-zone pay bar --}}
            <div class="flex max-h-[min(78vh,40rem)] flex-col" data-test="buy-now-form">
            <div class="min-h-0 flex-1 space-y-4 overflow-y-auto pe-0.5">
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                {{ __('main.buy_now') }}
            </flux:heading>
            @if ($buyNowProductName)
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ $buyNowProductName }}
                </flux:text>
            @endif

            @if ($buyNowError)
                <flux:callout variant="subtle" icon="exclamation-triangle" data-test="buy-now-error">
                    {{ $buyNowError }}
                </flux:callout>
            @endif

            @if ($buyNowSuccess)
                <flux:callout variant="subtle" icon="check-circle" data-test="buy-now-success">
                    <div class="space-y-3">
                        <div>{{ $buyNowSuccess }}</div>
                        <div class="flex flex-wrap gap-2">
                            @if ($buyNowOrderNumber)
                                <flux:button
                                    as="a"
                                    href="{{ route('orders.show', $buyNowOrderNumber) }}"
                                    wire:navigate
                                    variant="primary"
                                    size="sm"
                                >
                                    {{ __('messages.view_order') }}
                                </flux:button>
                            @endif
                            <flux:button variant="ghost" size="sm" wire:click="closeBuyNow">
                                {{ __('messages.close') }}
                            </flux:button>
                        </div>
                    </div>
                </flux:callout>
            @endif

            @unless ($buyNowSuccess)
            @auth
                @if ($this->walletAvailableToSpendAmount !== null && \App\Models\WebsiteSetting::getPricesVisible())
                    <x-purchase.affordability
                        :available="$this->walletAvailableToSpendAmount"
                        :total="$buyNowLineFinalPrice"
                        :needs-funds="$buyNowNeedsFunds"
                        add-funds-wire-click="continueToTopup"
                        compact
                    />
                @elseif (! \App\Models\WebsiteSetting::getPricesVisible())
                    <x-purchase.prices-gated context="buy_now" class="mb-1" />
                @endif
            @endauth

            @if ($buyNowError && $buyNowNeedsFunds)
                <div class="rounded-xl border border-amber-200 bg-amber-50/90 px-3 py-2.5 dark:border-amber-800/60 dark:bg-amber-950/30" data-test="buy-now-recovery">
                    <p class="text-xs font-medium text-amber-900 dark:text-amber-100">{{ $buyNowError }}</p>
                    <button
                        type="button"
                        wire:click="continueToTopup"
                        wire:loading.attr="disabled"
                        class="mt-2 text-xs font-semibold text-(--color-accent) underline-offset-2 hover:underline"
                        data-test="buy-now-recovery-topup"
                    >
                        {{ __('messages.cart_need_more_funds') }}
                    </button>
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                @if ($buyNowAmountMode === \App\Enums\ProductAmountMode::Custom->value)
                    <div class="contents">
                        <div class="space-y-1">
                            <flux:field>
                                <flux:label>{{ __('messages.amount') }}</flux:label>
                                <input
                                    type="text"
                                    step="500"
                                    inputmode="numeric"
                                    dir="{{ $bnHtmlDir }}"
                                    name="buyNowRequestedAmountDisplay"
                                    autocomplete="off"
                                    x-mask:dynamic="$money($input, '{{ $bnMaskDec }}', '{{ $bnMaskThousands }}', 0)"
                                    x-model="amountInputStr"
                                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-xs tabular-nums outline-none transition focus:border-(--color-accent) dark:border-white/10 dark:bg-white/10 dark:text-zinc-300 dark:shadow-none"
                                />
                            </flux:field>
                            <p class="text-[11px] text-zinc-500 dark:text-zinc-400">
                                {{ ($buyNowCustomAmountMin ?? '-') . ' - ' . ($buyNowCustomAmountMax ?? '-') . ' (' . $buyNowCustomAmountStep . ' step)' }}
                                @if ($buyNowAmountUnitLabel)
                                    {{ $buyNowAmountUnitLabel }}
                                @endif
                            </p>
                        </div>
                        @if (\App\Models\WebsiteSetting::getPricesVisible())
                            <div
                                class="flex gap-3 rounded-xl border px-4 py-3 sm:col-span-2 transition-[box-shadow,border-color,background-color] duration-200"
                                x-bind:class="{
                                    'border-zinc-200 bg-zinc-50/80 dark:border-zinc-700 dark:bg-zinc-800/50': estimateCardEmphasis === 'neutral' || estimateCardEmphasis === 'success',
                                    'border-rose-400/90 bg-gradient-to-br from-rose-50 via-white to-rose-50/80 shadow-md shadow-rose-200/40 ring-2 ring-rose-300/70 dark:border-rose-700 dark:from-rose-950/50 dark:via-zinc-900/60 dark:to-rose-950/30 dark:shadow-rose-950/30 dark:ring-rose-800/60': estimateCardEmphasis === 'attention',
                                    'border-red-400/90 bg-gradient-to-br from-red-50 via-white to-red-50/80 shadow-md shadow-red-200/40 ring-2 ring-red-300/70 dark:border-red-800 dark:from-red-950/45 dark:via-zinc-900/60 dark:to-red-950/30 dark:shadow-red-950/30 dark:ring-red-900/50': estimateCardEmphasis === 'error',
                                }"
                            >
                                <span
                                    class="mt-0.5 shrink-0"
                                    x-show="estimateCardEmphasis === 'attention'"
                                    x-cloak
                                    aria-hidden="true"
                                >
                                    <flux:icon icon="exclamation-triangle" class="size-5 text-rose-600 dark:text-rose-400" />
                                </span>
                                <span
                                    class="mt-0.5 shrink-0"
                                    x-show="estimateCardEmphasis === 'error'"
                                    x-cloak
                                    aria-hidden="true"
                                >
                                    <flux:icon icon="exclamation-circle" class="size-5 text-red-600 dark:text-red-400" />
                                </span>
                                <div class="min-w-0 flex-1">
                                    <div
                                        class="text-[11px] font-semibold uppercase tracking-wide"
                                        x-bind:class="{
                                            'text-zinc-500 dark:text-zinc-400': estimateCardEmphasis === 'neutral' || estimateCardEmphasis === 'success',
                                            'text-rose-800 dark:text-rose-200': estimateCardEmphasis === 'attention',
                                            'text-red-800 dark:text-red-200': estimateCardEmphasis === 'error',
                                        }"
                                    >
                                        {{ __('messages.estimated_total') }}
                                    </div>
                                    <div class="mt-1 min-h-[2rem] tabular-nums text-xl font-bold text-(--color-accent)" dir="{{ $bnNumericDir }}">
                                        <span
                                            x-show="serverError"
                                            x-text="serverError"
                                            class="block text-sm font-semibold text-red-700 dark:text-red-300"
                                        ></span>
                                        <span
                                            x-show="! serverError && estimatedFinal !== null"
                                            x-text="formatMoney(estimatedFinal)"
                                            class="block"
                                        ></span>
                                        <span
                                            x-show="! serverError && estimatedFinal === null"
                                            x-text="estimateHintText"
                                            class="block text-sm leading-snug"
                                            x-bind:class="{
                                                'font-normal text-zinc-500 dark:text-zinc-400': estimateCardEmphasis === 'neutral',
                                                'font-semibold text-rose-800 dark:text-rose-200': estimateCardEmphasis === 'attention',
                                            }"
                                        ></span>
                                    </div>
                                    <p class="mt-1 text-[10px] leading-relaxed text-zinc-500 dark:text-zinc-400">
                                        {{ __('messages.live_price_checkout_hint') }}
                                    </p>
                                </div>
                            </div>
                        @endif
                    </div>
                @else
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        type="number"
                        min="1"
                        name="buyNowQuantity"
                        label="{{ __('messages.quantity') }}"
                        :loading="false"
                        wire:model.live.blur="buyNowQuantity"
                    />

                    @if (\App\Models\WebsiteSetting::getPricesVisible())
                        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 px-4 py-3 dark:border-zinc-700 dark:bg-zinc-800/50 sm:col-span-2">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ __('messages.estimated_total') }}
                            </div>
                            <div
                                class="mt-1 min-h-[2rem] tabular-nums text-2xl font-bold text-(--color-accent)"
                                dir="{{ $bnNumericDir }}"
                            >
                                @if ($buyNowPriceError)
                                    <span class="text-sm font-semibold text-red-600 dark:text-red-400">{{ $buyNowPriceError }}</span>
                                @elseif ($buyNowLineFinalPrice !== null)
                                    <span x-text="$store.cart.format({{ (float) $buyNowLineFinalPrice }})">${{ number_format($buyNowLineFinalPrice, 2) }}</span>
                                @else
                                    <span class="text-sm font-normal text-zinc-500 dark:text-zinc-400">—</span>
                                @endif
                            </div>
                            <p class="mt-1 text-[11px] text-zinc-500 dark:text-zinc-400">
                                {{ __('messages.live_price_checkout_hint') }}
                            </p>
                        </div>
                    @endif
                @endif
            </div>
            @error('buyNowQuantity')
                <span class="text-[11px] text-red-600 dark:text-red-400">{{ $message }}</span>
            @enderror
            @error('buyNowRequestedAmount')
                <span class="text-[11px] text-red-600 dark:text-red-400">{{ $message }}</span>
            @enderror

            @if ($buyNowRequirementsSchema !== [])
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach ($buyNowRequirementsSchema as $requirement)
                        @php
                            $requirementKey = $requirement['key'] ?? null;
                        @endphp
                        @continue(empty($requirementKey))
                        <label class="flex flex-col gap-1 text-xs text-zinc-600 dark:text-zinc-300">
                            <span class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ $requirement['label'] ?? $requirementKey }}
                                @if (! empty($requirement['is_required']))
                                    <span class="text-amber-600 dark:text-amber-400">*</span>
                                @endif
                            </span>
                            @if (($requirement['type'] ?? '') === 'select' && ! empty($requirement['options']))
                                <select
                                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm outline-none transition focus:border-(--color-accent) dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                                    wire:model.live.change="buyNowRequirements.{{ $requirementKey }}"
                                >
                                    <option value="">--</option>
                                    @foreach ($requirement['options'] as $option)
                                        <option value="{{ $option }}">{{ $option }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input
                                    class="w-full rounded-lg border border-zinc-200 bg-white px-3 py-2 text-sm text-zinc-700 shadow-sm outline-none transition focus:border-(--color-accent) dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200"
                                    type="{{ ($requirement['type'] ?? '') === 'number' ? 'number' : 'text' }}"
                                    placeholder="{{ $requirement['label'] ?? $requirementKey }}"
                                    wire:model.live.blur="buyNowRequirements.{{ $requirementKey }}"
                                    autocomplete="off"
                                    spellcheck="false"
                                />
                            @endif
                            @error("buyNowRequirements.$requirementKey")
                                <span class="text-[11px] text-red-600 dark:text-red-400">{{ $message }}</span>
                            @enderror
                        </label>
                    @endforeach
                </div>
            @else
                <p class="text-sm text-zinc-500 dark:text-zinc-400">{{ __('messages.no_additional_requirements') }}</p>
            @endif
            </div>{{-- end scroll body --}}

            <div class="sticky bottom-0 z-10 mt-3 space-y-2 border-t border-zinc-200 bg-white pt-3 pb-[max(0.25rem,env(safe-area-inset-bottom))] dark:border-zinc-700 dark:bg-zinc-800" data-test="buy-now-sticky-actions">
                @if ($buyNowNeedsFunds)
                    <button
                        type="button"
                        wire:click="continueToTopup"
                        wire:loading.attr="disabled"
                        class="block w-full text-center text-xs font-semibold text-(--color-accent) hover:underline disabled:opacity-60"
                        data-test="buy-now-add-funds-link"
                    >
                        <span wire:loading.remove wire:target="continueToTopup">{{ __('messages.cart_need_more_funds') }}</span>
                        <span wire:loading wire:target="continueToTopup">{{ __('messages.please_wait') }}</span>
                    </button>
                @endif
                <div class="flex flex-wrap items-center justify-end gap-2">
                    <flux:button variant="ghost" wire:click="closeBuyNow">
                        {{ __('messages.cancel') }}
                    </flux:button>
                    @if ($buyNowAmountMode === \App\Enums\ProductAmountMode::Custom->value && $buyNowFinalPerUnitRate === null && ! (($buyNowClientPricingContext ?? [])['client_pricable'] ?? false))
                        <flux:button variant="primary" disabled class="min-h-11 flex-1 sm:flex-none">
                            {{ __('main.pay_now') }}
                        </flux:button>
                    @elseif ($buyNowAmountMode === \App\Enums\ProductAmountMode::Custom->value)
                        <button
                            type="button"
                            class="relative inline-flex min-h-11 flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-lg border border-black/10 bg-[var(--color-accent)] ps-4 pe-4 text-sm font-medium text-[var(--color-accent-foreground)] shadow-[inset_0px_1px_--theme(--color-white/.2)] hover:bg-[color-mix(in_oklab,_var(--color-accent),_transparent_10%)] disabled:pointer-events-none disabled:cursor-default disabled:opacity-75 dark:border-0 dark:disabled:opacity-75 sm:flex-none"
                            wire:loading.attr="disabled"
                            wire:target="submitBuyNow"
                            x-on:click.prevent="if (typeof amountValid !== 'undefined' && amountValid && typeof serverError !== 'undefined' && ! serverError && payableRate != null && {{ json_encode($this->buyNowCanSubmit) }}) { $wire.call('submitBuyNow', digitsParsed) }"
                            x-bind:disabled="typeof amountValid === 'undefined' || typeof serverError === 'undefined' || typeof payableRate === 'undefined' ? true : (! amountValid || !! serverError || payableRate == null || {{ json_encode(! $this->buyNowCanSubmit) }})"
                            data-test="buy-now-pay"
                        >
                            <span wire:loading.remove wire:target="submitBuyNow">{{ __('main.pay_now') }}</span>
                            <span wire:loading wire:target="submitBuyNow">{{ __('main.paying') }}</span>
                        </button>
                    @else
                        <flux:button
                            variant="primary"
                            wire:click="submitBuyNow"
                            wire:loading.attr="disabled"
                            wire:target="submitBuyNow"
                            wire:bind:disabled="{{ ! $this->buyNowCanSubmit }}"
                            class="min-h-11 flex-1 sm:flex-none"
                            data-test="buy-now-pay"
                        >
                            <span wire:loading.remove wire:target="submitBuyNow">{{ __('main.pay_now') }}</span>
                            <span wire:loading wire:target="submitBuyNow">{{ __('main.paying') }}</span>
                        </flux:button>
                    @endif
                </div>
                @if ($errors->has('buyNowRequirements.*'))
                    <p class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.requirements_required_checkout') }}
                    </p>
                @endif
            </div>
            @endunless
            </div>{{-- end buy-now-form flex --}}
        @endif
    </div>
</flux:modal>
