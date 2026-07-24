<?php

use App\Actions\Cart\RepriceCustomAmountLinePrice;
use App\Actions\Orders\CheckoutFromPayload;
use App\Actions\Packages\ResolvePackageRequirements;
use App\Enums\OrderStatus;
use App\Models\LoyaltySetting;
use App\Models\LoyaltyTierConfig;
use App\Models\Product;
use App\Models\Wallet;
use App\Services\LoyaltySpendService;
use App\Support\FrontendMoney;
use App\Support\PurchaseResumeIntent;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

new #[Layout('layouts::frontend')] class extends Component
{
    use Toastable;

    public ?string $checkoutError = null;
    public ?string $checkoutSuccess = null;
    public ?string $lastOrderNumber = null;
    public bool $checkoutNeedsFunds = false;
    public array $requirementsByProduct = [];
    public array $cartRequirements = [];
    public array $customAmountPriceMeta = [];

    public function checkout(mixed $items): void
    {
        $this->reset('checkoutError', 'checkoutSuccess', 'lastOrderNumber', 'checkoutNeedsFunds');

        if (! auth()->check()) {
            $this->checkoutError = __('messages.sign_in_to_checkout');
            $this->error($this->checkoutError);

            return;
        }

        $user = auth()->user();

        if (! $this->validateCartItems($items)) {
            return;
        }

        try {
            $checkout = app(CheckoutFromPayload::class)->handle(
                $user,
                $items,
                [
                    'ip' => request()->ip(),
                    'user_agent' => request()->userAgent(),
                ]
            );

            $order = $checkout->order;

            if (! $order->exists || $order->status !== OrderStatus::Paid) {
                $this->checkoutError = __('messages.checkout_could_not_complete');
                $this->error($this->checkoutError);

                return;
            }

            $this->checkoutSuccess = $checkout->reusedExistingOrder
                ? __('messages.checkout_order_already_placed', ['order_number' => $order->order_number])
                : __('messages.payment_successful_order_processing', ['order_number' => $order->order_number]);
            $this->lastOrderNumber = $order->order_number;
            $this->success($this->checkoutSuccess);
            PurchaseResumeIntent::forget();
            $this->dispatch('checkout-success', orderNumber: $order->order_number);
        } catch (ValidationException $exception) {
            $this->checkoutError = collect($exception->errors())->flatten()->first()
                ?? __('messages.checkout_validation_failed');
            $this->checkoutNeedsFunds = array_key_exists('wallet', $exception->errors());
            $this->error($this->checkoutError);
        } catch (\Throwable) {
            $this->checkoutError = __('messages.something_went_wrong_checkout');
            $this->error($this->checkoutError);
        }
    }

    public function continueToTopup(?string $amount = null): void
    {
        if (! auth()->check()) {
            $this->checkoutError = __('messages.sign_in_to_checkout');
            $this->error($this->checkoutError);

            return;
        }

        PurchaseResumeIntent::store(['source' => PurchaseResumeIntent::SOURCE_CART]);

        $query = [];
        if (is_string($amount) && is_numeric($amount) && (float) $amount > 0) {
            $query['amount'] = number_format((float) $amount, 2, '.', '');
        }

        $this->redirect(route('wallet.topup', $query), navigate: true);
    }

    public function loadRequirements(array $productIds): void
    {
        $ids = collect($productIds)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $resolver = app(ResolvePackageRequirements::class);
        $resolvedByProduct = [];
        $schemaByProduct = [];

        $products = Product::query()
            ->with('package.requirements')
            ->whereIn('id', $ids)
            ->get();

        foreach ($products as $product) {
            $resolved = $resolver->handle($product->package?->requirements ?? collect());
            $resolvedByProduct[$product->id] = $resolved;
            $schemaByProduct[$product->id] = $resolved['schema'];
        }

        $this->requirementsByProduct = $resolvedByProduct;
        $this->dispatch('requirements-loaded', requirements: $schemaByProduct);
    }

    public function validateCartRequirement(int $productId, string $key, mixed $value): void
    {
        $resolved = $this->resolveRequirementsForProduct($productId);

        if ($resolved['rules'] === [] || ! array_key_exists($key, $resolved['rules'])) {
            $this->dispatch('cart-requirement-validation', productId: $productId, key: $key, message: null);
            return;
        }

        data_set($this->cartRequirements, $productId.'.'.$key, $value);

        [$rules, $attributes] = $this->buildCartRulesForProduct($productId, $resolved);

        try {
            $this->validateOnly("cartRequirements.$productId.$key", $rules, [], $attributes);
            $this->dispatch('cart-requirement-validation', productId: $productId, key: $key, message: null);
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();
            $this->dispatch('cart-requirement-validation', productId: $productId, key: $key, message: $message);
        }
    }

    public function repriceCustomAmount(int $productId, mixed $requestedAmount): void
    {
        $identity = (string) (auth()->id() ?? request()->ip() ?? 'guest');
        $result = app(RepriceCustomAmountLinePrice::class)->handle(
            $productId,
            $requestedAmount,
            auth()->user(),
            $identity,
        );

        if (($result['silent'] ?? false) === true) {
            return;
        }

        if (! $result['ok']) {
            $this->dispatch('cart-custom-amount-priced', productId: $productId, message: $result['message']);

            return;
        }

        $this->customAmountPriceMeta[$productId] = $result['meta'];
        $this->dispatch(
            'cart-custom-amount-priced',
            productId: $productId,
            price: $result['price'],
            requestedAmount: $result['requested_amount'],
            message: null,
        );
    }

    #[Computed]
    public function loyaltyCurrentTierConfig(): ?LoyaltyTierConfig
    {
        $user = auth()->user();
        if ($user === null || $user->loyaltyRole() === null) {
            return null;
        }
        $tierName = $user->loyalty_tier?->value ?? 'bronze';

        return LoyaltyTierConfig::query()->forRole($user->loyaltyRole())->where('name', $tierName)->first();
    }

    #[Computed]
    public function loyaltyRollingSpend(): float
    {
        $user = auth()->user();
        if ($user === null) {
            return 0.0;
        }
        $windowDays = LoyaltySetting::getRollingWindowDays();

        return app(LoyaltySpendService::class)->computeRollingSpend($user, $windowDays);
    }

    #[Computed]
    public function loyaltyNextTier(): ?LoyaltyTierConfig
    {
        $user = auth()->user();
        if ($user === null || $user->loyaltyRole() === null) {
            return null;
        }
        return LoyaltyTierConfig::query()
            ->forRole($user->loyaltyRole())
            ->where('min_spend', '>', $this->loyaltyRollingSpend)
            ->orderBy('min_spend')
            ->first();
    }

    #[Computed]
    public function loyaltyProgressPercent(): ?float
    {
        $next = $this->loyaltyNextTier;
        if ($next === null) {
            return null;
        }
        $threshold = (float) $next->min_spend;
        if ($threshold <= 0) {
            return 100.0;
        }
        return min(100.0, round(($this->loyaltyRollingSpend / $threshold) * 100, 1));
    }

    #[Computed]
    public function loyaltyAmountToNextTier(): ?float
    {
        $next = $this->loyaltyNextTier;
        if ($next === null) {
            return null;
        }
        return max(0.0, (float) $next->min_spend - $this->loyaltyRollingSpend);
    }

    #[Computed]
    public function customerWallet(): ?Wallet
    {
        if (! auth()->check()) {
            return null;
        }

        return Wallet::forUser(auth()->user());
    }

    #[Computed]
    public function walletAvailableToSpend(): ?string
    {
        return $this->customerWallet?->availableToSpend();
    }

    #[Computed]
    public function walletOutstandingDebt(): ?string
    {
        $wallet = $this->customerWallet;

        if ($wallet === null || ! $wallet->isOverdrawn()) {
            return null;
        }

        return $wallet->outstandingDebt();
    }

    public function render(): View
    {
        return $this->view()->title(__('main.shopping_cart'));
    }

    private function validateCartItems(mixed $items): bool
    {
        if (! is_array($items)) {
            return true;
        }

        $this->syncCartRequirementsFromItems($items);
        [$rules, $attributes] = $this->buildCartRulesForItems($items);

        if ($rules === []) {
            $this->dispatch('cart-requirement-errors', errors: []);
            return true;
        }

        try {
            $this->validate($rules, [], $attributes);
            $this->dispatch('cart-requirement-errors', errors: []);
            return true;
        } catch (ValidationException $exception) {
            $this->dispatchCartRequirementErrors($exception->errors());
            $this->checkoutError = collect($exception->errors())->flatten()->first()
                ?? __('messages.checkout_validation_failed');
            $this->error($this->checkoutError);

            return false;
        }
    }

    private function resolveRequirementsForProduct(int $productId): array
    {
        if (isset($this->requirementsByProduct[$productId])) {
            return $this->requirementsByProduct[$productId];
        }

        $product = Product::query()
            ->with('package.requirements')
            ->whereKey($productId)
            ->first();

        if ($product === null) {
            return ['schema' => [], 'rules' => [], 'attributes' => []];
        }

        $resolved = app(ResolvePackageRequirements::class)
            ->handle($product->package?->requirements ?? collect());

        $this->requirementsByProduct[$productId] = $resolved;

        return $resolved;
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{0: array<string, array<int, string>>, 1: array<string, string>}
     */
    private function buildCartRulesForItems(array $items): array
    {
        $rules = [];
        $attributes = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $resolved = $this->resolveRequirementsForProduct($productId);
            [$itemRules, $itemAttributes] = $this->buildCartRulesForProduct($productId, $resolved);

            $rules = array_merge($rules, $itemRules);
            $attributes = array_merge($attributes, $itemAttributes);
        }

        return [$rules, $attributes];
    }

    /**
     * @return array{0: array<string, array<int, string>>, 1: array<string, string>}
     */
    private function buildCartRulesForProduct(int $productId, array $resolved): array
    {
        $rules = [];
        $attributes = [];

        foreach ($resolved['rules'] ?? [] as $key => $ruleSet) {
            $rules["cartRequirements.$productId.$key"] = $ruleSet;
            $attributes["cartRequirements.$productId.$key"] = $resolved['attributes'][$key] ?? $key;
        }

        return [$rules, $attributes];
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncCartRequirementsFromItems(array $items): void
    {
        $requirements = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $productId = (int) ($item['product_id'] ?? $item['id'] ?? 0);

            if ($productId <= 0) {
                continue;
            }

            $payload = $item['requirements'] ?? $item['requirements_payload'] ?? [];

            $requirements[$productId] = is_array($payload) ? $payload : [];
        }

        $this->cartRequirements = $requirements;
    }

    /**
     * @param  array<string, array<int, string>>  $errors
     */
    private function dispatchCartRequirementErrors(array $errors): void
    {
        $mapped = [];

        foreach ($errors as $field => $messages) {
            if (! str_starts_with($field, 'cartRequirements.')) {
                continue;
            }

            $segments = explode('.', $field, 3);

            if (count($segments) < 3) {
                continue;
            }

            $productId = $segments[1];
            $key = $segments[2];
            $message = is_array($messages) ? ($messages[0] ?? null) : $messages;

            if ($message === null) {
                continue;
            }

            $mapped[$productId][$key] = $message;
        }

        $this->dispatch('cart-requirement-errors', errors: $mapped);
    }

};
?>

@php
    $cartLocaleTag = str_replace('_', '-', app()->getLocale());
    $cartAmountMaskEn = str_starts_with($cartLocaleTag, 'en');
    $cartMaskDec = $cartAmountMaskEn ? '.' : ',';
    $cartMaskThousands = $cartAmountMaskEn ? ',' : '.';
    $cartImageFallback = asset('images/promotions/promo-placeholder.svg');
    $cartInputClass = 'h-10 w-full rounded-lg border border-zinc-200 bg-white px-3 text-sm text-zinc-700 shadow-sm outline-none transition placeholder:text-zinc-400 focus:border-(--color-accent) focus:ring-2 focus:ring-(--color-accent)/15 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100 dark:placeholder:text-zinc-500';
    $cartMoney = FrontendMoney::for(auth()->user());
    $cartPricesVisible = \App\Models\WebsiteSetting::getPricesVisible();
@endphp

<div
    class="storefront-page storefront-page--browse"
    x-data="{
        itemsLabel: @js(__('messages.items')),
        itemLabel: @js(__('messages.item')),
        cartCountHeading(c) {
            return c === 1 ? `${c} ${this.itemLabel}` : `${c} ${this.itemsLabel}`;
        },
    }"
    x-init="
        $store.cart.init();
        $store.cart.showCartRequirementErrors = false;
        $store.cart.setValidationMessages({
            required: @js(__('messages.required_field')),
            numeric: @js(__('messages.numeric')),
            min_chars: @js(__('messages.min_chars')),
            max_chars: @js(__('messages.max_chars')),
            min_value: @js(__('messages.min_value')),
            max_value: @js(__('messages.max_value')),
            min_digits: @js(__('messages.min_digits')),
            max_digits: @js(__('messages.max_digits')),
            in: @js(__('messages.in_values')),
            invalid_format: @js(__('messages.invalid_format')),
            invalid_value: @js(__('messages.invalid_value')),
        });
        if ($store.cart.items.length) {
            $wire.loadRequirements($store.cart.items.map(item => item.id));
        }
    "
    x-on:requirements-loaded.window="$store.cart.applyRequirementsSchema($event.detail.requirements)"
    x-on:cart-requirement-validation.window="$store.cart.setServerRequirementError($event.detail)"
    x-on:cart-requirement-errors.window="$store.cart.setServerRequirementErrors($event.detail.errors)"
    x-on:cart-custom-amount-priced.window="
        if ($event.detail?.price !== undefined) {
            $store.cart.applyCustomAmountPrice($event.detail);
        }
        $store.cart.setCustomAmountError($event.detail);
    "
    x-on:checkout-success.window="$store.cart.clear()"
    data-test="cart-page"
    data-storefront-page="browse"
>
    @php
        $cartResumeIntent = \App\Support\PurchaseResumeIntent::peek();
        $showCartResumeBanner = session('purchase_resume_ready')
            || (($cartResumeIntent['source'] ?? null) === \App\Support\PurchaseResumeIntent::SOURCE_CART);
    @endphp
    @if ($showCartResumeBanner)
        <flux:callout variant="subtle" icon="shopping-cart" class="mb-4" data-test="purchase-resume-banner">
            <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                {{ __('messages.purchase_resume_cart_ready') }}
            </flux:text>
            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('messages.purchase_resume_banner_body') }}
            </flux:text>
        </flux:callout>
    @endif
    <div class="flex flex-col gap-6 lg:flex-row lg:items-start">
        <section class="flex-1">
            <div class="storefront-card storefront-card--pad-md">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg" class="storefront-type-title text-zinc-900 dark:text-zinc-100">
                        {{ __('main.my_cart') }}
                    </flux:heading>
                    <span
                        class="text-sm tabular-nums text-zinc-500 dark:text-zinc-400"
                        x-text="cartCountHeading($store.cart.count)"
                        x-show="$store.cart.count > 0"
                    ></span>
                </div>

                <ul class="mt-6 space-y-4">
                    <template x-if="$store.cart.items.length === 0">
                        <li class="flex flex-col items-center gap-3 py-10 text-center">
                            <div class="flex size-12 items-center justify-center rounded-full bg-zinc-100 text-zinc-500 dark:bg-zinc-900 dark:text-zinc-300">
                                <flux:icon icon="shopping-cart" class="size-5" />
                            </div>
                            <div class="text-sm text-zinc-600 dark:text-zinc-300">
                                {{ __('main.cart_empty') }}
                            </div>
                            <a
                                href="{{ route('home') }}"
                                wire:navigate
                                class="inline-flex items-center justify-center rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-700 shadow-sm transition hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                            >
                                {{ __('main.continue_shopping') }}
                            </a>
                        </li>
                    </template>

                    <template x-for="item in $store.cart.items" :key="item.id">
                        <li>
                            <article
                                class="storefront-card storefront-card--pad-md"
                            >
                                <div class="flex gap-4">
                                    <div
                                        class="relative h-20 w-20 shrink-0 overflow-hidden rounded-xl border border-zinc-200 bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-800"
                                    >
                                        <img
                                            :src="item.image"
                                            :alt="item.name"
                                            class="h-full w-full object-cover"
                                            loading="lazy"
                                            onerror="this.onerror=null;this.src='{{ $cartImageFallback }}'"
                                        />
                                    </div>
                                    <div class="min-w-0 flex-1" dir="auto">
                                        <a
                                            :href="item.href || '#'"
                                            class="line-clamp-2 text-base font-semibold leading-snug text-zinc-900 hover:underline dark:text-zinc-100"
                                            x-text="item.name"
                                        ></a>
                                        @if(\App\Models\WebsiteSetting::getPricesVisible())
                                        <div class="mt-2 flex flex-col gap-2 sm:flex-row sm:flex-wrap sm:items-baseline sm:gap-x-4 sm:gap-y-1">
                                            <template x-if="item.amount_mode === 'custom'">
                                                <span
                                                    class="inline-flex w-fit items-center rounded-md bg-(--color-accent)/10 px-2 py-1 text-sm font-semibold tabular-nums text-(--color-accent)"
                                                    dir="ltr"
                                                    x-text="$store.cart.formatPerUnitCurrency($store.cart.customUnitRateForDisplay(item))"
                                                ></span>
                                            </template>
                                            <template x-if="item.amount_mode !== 'custom'">
                                                <span
                                                    class="inline-flex w-fit items-center rounded-md bg-(--color-accent)/10 px-2 py-1 text-sm font-semibold tabular-nums text-(--color-accent)"
                                                    dir="ltr"
                                                    x-text="$store.cart.format(item.price)"
                                                ></span>
                                            </template>
                                            <template x-if="item.amount_mode === 'custom'">
                                                <span
                                                    class="text-sm text-zinc-600 dark:text-zinc-400"
                                                    x-text="`${Number(item.requested_amount ?? 0).toLocaleString()} ${item.amount_unit_label ?? ''}`.trim()"
                                                ></span>
                                            </template>
                                        </div>
                                        @else
                                        <div class="mt-2 text-sm font-medium text-zinc-500 dark:text-zinc-400" dir="ltr">—</div>
                                        @endif
                                    </div>
                                    <button
                                        type="button"
                                        class="-me-1 -mt-1 inline-flex size-9 shrink-0 items-center justify-center rounded-lg text-zinc-400 transition hover:bg-zinc-100 hover:text-zinc-600 dark:hover:bg-zinc-800 dark:hover:text-zinc-200"
                                        x-on:click.stop="$store.cart.remove(item.id)"
                                        aria-label="{{ __('main.remove_item') }}"
                                    >
                                        <flux:icon icon="x-mark" class="size-5" />
                                    </button>
                                </div>

                                <template x-if="item.requirements_schema && item.requirements_schema.length">
                                    <div
                                        class="mt-5 border-t border-zinc-100 pt-5 dark:border-zinc-800"
                                    >
                                        <p
                                            class="mb-4 text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                                        >
                                            {{ __('main.cart_required_fields') }}
                                        </p>
                                        <div class="grid gap-5 sm:grid-cols-2">
                                            <template x-for="requirement in item.requirements_schema" :key="requirement.key">
                                                <label class="flex flex-col gap-2">
                                                    <span class="text-sm font-medium text-zinc-800 dark:text-zinc-200">
                                                        <span x-text="requirement.label || requirement.key"></span>
                                                        <span
                                                            x-show="requirement.is_required"
                                                            class="ms-0.5 text-amber-600 dark:text-amber-400"
                                                        >*</span>
                                                    </span>
                                                    <template x-if="requirement.type === 'select' && Array.isArray(requirement.options) && requirement.options.length">
                                                        <select
                                                            class="{{ $cartInputClass }}"
                                                            x-bind:class="{
                                                                'border-red-500 ring-red-500/20 focus:border-red-500': $store.cart.getRequirementError(item, requirement),
                                                            }"
                                                            x-on:change="
                                                                $store.cart.updateRequirement(item.id, requirement.key, $event.target.value);
                                                                $wire.validateCartRequirement(item.id, requirement.key, $event.target.value);
                                                            "
                                                            :value="item.requirements?.[requirement.key] ?? ''"
                                                        >
                                                            <option value="">--</option>
                                                            <template x-for="option in requirement.options" :key="option">
                                                                <option :value="option" x-text="option"></option>
                                                            </template>
                                                        </select>
                                                    </template>
                                                    <template x-if="!(requirement.type === 'select' && Array.isArray(requirement.options) && requirement.options.length)">
                                                        <input
                                                            class="{{ $cartInputClass }}"
                                                            x-bind:class="{
                                                                'border-red-500 ring-red-500/20 focus:border-red-500': $store.cart.getRequirementError(item, requirement),
                                                            }"
                                                            :type="requirement.type === 'number' ? 'number' : 'text'"
                                                            :placeholder="requirement.label || requirement.key"
                                                            x-on:input="$store.cart.updateRequirement(item.id, requirement.key, $event.target.value)"
                                                            x-on:blur="$wire.validateCartRequirement(item.id, requirement.key, $event.target.value)"
                                                            :value="item.requirements?.[requirement.key] ?? ''"
                                                        />
                                                    </template>
                                                    <span
                                                        class="min-h-[1.25rem] text-xs leading-snug text-red-600 dark:text-red-400"
                                                        x-show="$store.cart.getRequirementError(item, requirement)"
                                                        x-text="$store.cart.getRequirementError(item, requirement)"
                                                    ></span>
                                                </label>
                                            </template>
                                        </div>
                                    </div>
                                </template>

                                <div
                                    class="mt-5 flex flex-col gap-5 border-t border-zinc-100 pt-5 sm:flex-row sm:items-end sm:justify-between sm:gap-8 dark:border-zinc-800"
                                >
                                    <div class="w-full min-w-0 sm:max-w-sm sm:flex-1">
                                        <template x-if="item.amount_mode === 'custom'">
                                            <div class="flex flex-col gap-2">
                                                <span
                                                    class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                                                >{{ __('main.cart_order_amount') }}</span>
                                                <input
                                                    type="text"
                                                    inputmode="numeric"
                                                    dir="ltr"
                                                    class="{{ $cartInputClass }} tabular-nums"
                                                    x-mask:dynamic="$money($input, '{{ $cartMaskDec }}', '{{ $cartMaskThousands }}', 0)"
                                                    x-model="item.requested_amount_input"
                                                    x-on:blur="
                                                        const isValid = $store.cart.updateRequestedAmount(item.id, item.requested_amount_input);
                                                        if (isValid) {
                                                            $wire.repriceCustomAmount(item.id, item.requested_amount);
                                                        }
                                                    "
                                                />
                                                <p class="text-xs leading-relaxed text-zinc-500 dark:text-zinc-400" dir="ltr">
                                                    <span
                                                        x-text="`${item.custom_amount_min ? Number(item.custom_amount_min).toLocaleString() : '-'} – ${item.custom_amount_max ? Number(item.custom_amount_max).toLocaleString() : '-'} · ${item.custom_amount_step ?? 1} ${item.amount_unit_label ?? ''}`.trim()"
                                                    ></span>
                                                </p>
                                                <p
                                                    class="text-xs text-red-600 dark:text-red-400"
                                                    x-show="$store.cart.getCustomAmountError(item)"
                                                    x-text="$store.cart.getCustomAmountError(item)"
                                                ></p>
                                            </div>
                                        </template>
                                        <template x-if="item.amount_mode !== 'custom'">
                                            <div class="flex flex-col gap-2">
                                                <span
                                                    class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                                                >{{ __('main.cart_quantity') }}</span>
                                                <div
                                                    class="inline-flex h-10 max-w-[11rem] items-center gap-1 rounded-lg border border-zinc-200 bg-white px-1 dark:border-zinc-600 dark:bg-zinc-900"
                                                >
                                                    <button
                                                        type="button"
                                                        class="inline-flex size-9 items-center justify-center rounded-md text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                                        x-on:click.stop="$store.cart.decrement(item.id)"
                                                        aria-label="{{ __('main.decrease') }}"
                                                    >
                                                        <flux:icon icon="minus" class="size-4" />
                                                    </button>
                                                    <span
                                                        class="min-w-8 flex-1 text-center text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-100"
                                                        x-text="item.quantity"
                                                    ></span>
                                                    <button
                                                        type="button"
                                                        class="inline-flex size-9 items-center justify-center rounded-md text-zinc-600 transition hover:bg-zinc-100 dark:text-zinc-300 dark:hover:bg-zinc-800"
                                                        x-on:click.stop="$store.cart.increment(item.id)"
                                                        aria-label="{{ __('main.increase') }}"
                                                    >
                                                        <flux:icon icon="plus" class="size-4" />
                                                    </button>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                    <div
                                        class="flex w-full flex-col gap-1 border-t border-zinc-100 pt-4 sm:w-auto sm:min-w-[8rem] sm:border-t-0 sm:pt-0 sm:text-end dark:border-zinc-800"
                                    >
                                        @if(\App\Models\WebsiteSetting::getPricesVisible())
                                        <span
                                            class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                                        >{{ __('main.cart_line_total') }}</span>
                                        <span
                                            class="text-xl font-bold tabular-nums text-(--color-accent) sm:text-2xl"
                                            dir="ltr"
                                            x-text="$store.cart.format($store.cart.lineTotalForItem(item))"
                                        ></span>
                                        @else
                                        <span
                                            class="text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
                                        >{{ __('main.cart_line_total') }}</span>
                                        <span class="text-xl font-semibold text-zinc-500 dark:text-zinc-400" dir="ltr">—</span>
                                        @endif
                                    </div>
                                </div>
                            </article>
                        </li>
                    </template>
                </ul>
            </div>
        </section>

        <aside class="w-full lg:w-80 lg:sticky lg:top-24">
            <div class="storefront-card storefront-card--pad-md">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('main.order_summary') }}
                </flux:heading>

                @if ($this->loyaltyCurrentTierConfig !== null)
                    <div class="mt-4">
                        <x-loyalty.tier-card
                            :current-tier-name="auth()->user()?->loyalty_tier?->value ?? 'bronze'"
                            :discount-percent="(float) $this->loyaltyCurrentTierConfig->discount_percentage"
                            :rolling-spend="$this->loyaltyRollingSpend"
                            :next-tier-name="$this->loyaltyNextTier?->name"
                            :next-tier-min-spend="$this->loyaltyNextTier ? (float) $this->loyaltyNextTier->min_spend : null"
                            :amount-to-next="$this->loyaltyAmountToNextTier"
                            :progress-percent="$this->loyaltyProgressPercent"
                            :window-days="\App\Models\LoyaltySetting::getRollingWindowDays()"
                            layout="compact"
                        />
                    </div>
                @endif

                @if ($checkoutError)
                    <flux:callout variant="subtle" icon="exclamation-triangle" class="mt-4">
                        {{ $checkoutError }}
                    </flux:callout>
                @endif

                @if ($checkoutSuccess)
                    <flux:callout variant="subtle" icon="check-circle" class="mt-4">
                        <div class="space-y-3">
                            <div>{{ $checkoutSuccess }}</div>
                            @if ($lastOrderNumber)
                                <div class="flex flex-wrap gap-2">
                                    <flux:button
                                        as="a"
                                        href="{{ route('orders.show', $lastOrderNumber) }}"
                                        wire:navigate
                                        variant="outline"
                                        size="sm"
                                    >
                                        {{ __('messages.view_order') }}
                                    </flux:button>
                                </div>
                            @endif
                        </div>
                    </flux:callout>
                @endif

                <div class="mt-4 space-y-3 text-sm">
                    @auth
                        @if ($cartPricesVisible && $this->walletOutstandingDebt !== null)
                            <div
                                class="flex items-center justify-between text-zinc-600 dark:text-zinc-300"
                                data-test="cart-amount-you-owe"
                            >
                                <span>{{ __('messages.cart_amount_you_owe') }}</span>
                                <span class="font-semibold tabular-nums text-red-700 dark:text-red-400" dir="ltr">
                                    {{ $cartMoney->format((float) $this->walletOutstandingDebt, 'USD', 2) }}
                                </span>
                            </div>
                        @endif
                    @endauth
                    <div class="flex items-center justify-between text-zinc-600 dark:text-zinc-300">
                        <span>{{ __('messages.subtotal') }}</span>
                        @if($cartPricesVisible)
                        <span class="font-semibold text-zinc-900 dark:text-zinc-100" dir="ltr" x-text="$store.cart.format($store.cart.subtotal)"></span>
                        @else
                        <span class="font-semibold text-zinc-500 dark:text-zinc-400">—</span>
                        @endif
                    </div>
                    @if($cartPricesVisible)
                    <div class="flex items-center justify-between text-green-600 dark:text-green-400" x-show="$store.cart.loyaltyDiscount > 0" x-cloak>
                        <span>{{ __('messages.loyalty_discount') }} <span x-show="$store.cart.loyaltyTierName" x-text="'(' + $store.cart.loyaltyTierLabel + ')'"></span>:</span>
                        <span class="font-semibold" dir="ltr" x-text="'-' + $store.cart.format($store.cart.loyaltyDiscount)"></span>
                    </div>
                    @endif
                    <div class="flex items-center justify-between text-zinc-600 dark:text-zinc-300">
                        <span>{{ __('messages.purchase_instant_delivery') }}</span>
                        <span class="font-semibold text-emerald-700 dark:text-emerald-400">{{ __('main.free_shipping') }}</span>
                    </div>
                </div>

                <div class="mt-4 flex items-center justify-between border-t border-zinc-100 pt-4 text-base font-semibold dark:border-zinc-700">
                    <span class="text-zinc-900 dark:text-zinc-100">{{ __('messages.total') }}</span>
                    @if($cartPricesVisible)
                    <span class="text-(--color-accent)" dir="ltr" x-text="$store.cart.format($store.cart.subtotal - $store.cart.loyaltyDiscount)"></span>
                    @else
                    <span class="text-zinc-500 dark:text-zinc-400">—</span>
                    @endif
                </div>
                @unless ($cartPricesVisible)
                    <div class="mt-3">
                        <x-purchase.prices-gated context="cart" />
                    </div>
                @endunless
                <p
                    class="mt-1 text-xs text-zinc-500 dark:text-zinc-400"
                    x-show="$store.cart.displayCurrencyCode() === 'TRY'"
                    x-cloak
                >
                    {{ __('messages.amount') }}: {{ __('messages.estimated_total') }} (TRY, approx)
                </p>

                @auth
                    @if ($cartPricesVisible && $this->walletAvailableToSpend !== null)
                        <div
                            class="mt-4"
                            data-test="cart-affordability"
                            x-data="{
                                available: {{ (float) $this->walletAvailableToSpend }},
                                get total() { return Number($store.cart.subtotal - $store.cart.loyaltyDiscount) || 0 },
                                get shortfall() { return Math.max(0, this.total - this.available) },
                                get needsMore() { return this.shortfall > 0.009 || {{ $checkoutNeedsFunds ? 'true' : 'false' }} },
                            }"
                        >
                            <x-purchase.affordability
                                :available="(float) $this->walletAvailableToSpend"
                                :needs-funds="$checkoutNeedsFunds"
                                add-funds-wire-click="continueToTopup"
                                compact
                            />
                            <button
                                type="button"
                                x-show="needsMore"
                                x-cloak
                                x-on:click="$wire.continueToTopup(shortfall > 0.009 ? shortfall.toFixed(2) : null)"
                                class="mt-2 block w-full text-center text-xs font-semibold text-(--color-accent) hover:underline"
                                data-test="cart-add-funds-shortfall"
                            >
                                {{ __('messages.cart_need_more_funds') }}
                            </button>
                        </div>
                    @endif
                @endauth

                <div class="mt-4 hidden space-y-3 lg:block">
                    <flux:button
                        variant="primary"
                        class="w-full justify-center !bg-accent !text-accent-foreground hover:!bg-accent-hover"
                        x-bind:disabled="$store.cart.count === 0 || $store.cart.hasMissingRequirements || $store.cart.hasCustomAmountErrors"
                        x-on:click="$wire.checkout($store.cart.checkoutItems)"
                        wire:loading.attr="disabled"
                        wire:target="checkout"
                        data-test="cart-checkout"
                    >
                        <span wire:loading.remove wire:target="checkout">{{ __('main.proceed_to_checkout') }}</span>
                        <span wire:loading wire:target="checkout">{{ __('main.checking_out') }}</span>
                    </flux:button>
                    @auth
                        @if ($checkoutNeedsFunds)
                            <button
                                type="button"
                                wire:click="continueToTopup"
                                wire:loading.attr="disabled"
                                class="block w-full text-center text-xs font-medium text-(--color-accent) hover:underline disabled:opacity-60"
                                data-test="cart-add-funds-link"
                            >
                                <span wire:loading.remove wire:target="continueToTopup">{{ __('messages.cart_need_more_funds') }}</span>
                                <span wire:loading wire:target="continueToTopup">{{ __('messages.please_wait') }}</span>
                            </button>
                        @endif
                    @endauth
                    <p
                        class="text-xs text-zinc-500 dark:text-zinc-400"
                        x-show="$store.cart.hasMissingRequirements && ! $store.cart.showCartRequirementErrors"
                    >
                        {{ __('messages.requirements_required_checkout') }}
                    </p>
                    <button
                        type="button"
                        class="w-full rounded-lg border border-zinc-200 bg-white px-4 py-2 text-sm font-semibold text-zinc-700 shadow-sm transition hover:bg-zinc-100 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:bg-zinc-800"
                        x-bind:disabled="$store.cart.count === 0"
                        x-on:click="$store.cart.clear()"
                        data-test="cart-clear"
                    >
                        {{ __('main.clear_cart') }}
                    </button>
                </div>
            </div>
        </aside>
    </div>

    {{-- Mobile thumb-zone checkout bar --}}
    <div
        class="storefront-sticky-cta fixed inset-x-0 z-40 border-t border-zinc-200 bg-white/95 px-3 py-3 shadow-[0_-8px_24px_rgb(0_0_0/0.08)] backdrop-blur dark:border-zinc-700 dark:bg-zinc-900/95 lg:hidden"
        x-show="$store.cart.count > 0"
        x-cloak
        data-test="cart-sticky-checkout"
    >
        <div class="mx-auto flex w-full max-w-7xl flex-col gap-2">
            <div class="flex items-center justify-between gap-3 text-sm">
                <span class="font-medium text-zinc-600 dark:text-zinc-300">{{ __('messages.total') }}</span>
                @if ($cartPricesVisible)
                    <span class="text-lg font-bold tabular-nums text-(--color-accent)" dir="ltr" x-text="$store.cart.format($store.cart.subtotal - $store.cart.loyaltyDiscount)"></span>
                @else
                    <span class="text-lg font-semibold text-zinc-500">—</span>
                @endif
            </div>
            @auth
                @if ($checkoutNeedsFunds)
                    <button
                        type="button"
                        wire:click="continueToTopup"
                        wire:loading.attr="disabled"
                        class="text-center text-xs font-semibold text-(--color-accent) hover:underline disabled:opacity-60"
                        data-test="cart-sticky-add-funds"
                    >
                        <span wire:loading.remove wire:target="continueToTopup">{{ __('messages.cart_need_more_funds') }}</span>
                        <span wire:loading wire:target="continueToTopup">{{ __('messages.please_wait') }}</span>
                    </button>
                @endif
            @endauth
            <flux:button
                variant="primary"
                class="min-h-12 w-full justify-center !bg-accent !text-accent-foreground hover:!bg-accent-hover"
                x-bind:disabled="$store.cart.count === 0 || $store.cart.hasMissingRequirements || $store.cart.hasCustomAmountErrors"
                x-on:click="$wire.checkout($store.cart.checkoutItems)"
                wire:loading.attr="disabled"
                wire:target="checkout"
                data-test="cart-checkout-sticky"
            >
                <span wire:loading.remove wire:target="checkout">{{ __('main.proceed_to_checkout') }}</span>
                <span wire:loading wire:target="checkout">{{ __('main.checking_out') }}</span>
            </flux:button>
            <p
                class="text-center text-xs text-zinc-500 dark:text-zinc-400"
                x-show="$store.cart.hasMissingRequirements && ! $store.cart.showCartRequirementErrors"
            >
                {{ __('messages.requirements_required_checkout') }}
            </p>
        </div>
    </div>
    <div
        class="lg:hidden"
        style="height: calc(var(--storefront-sticky-offset) + 7rem);"
        x-show="$store.cart.count > 0"
        x-cloak
        aria-hidden="true"
        data-test="cart-sticky-spacer"
    ></div>
</div>
