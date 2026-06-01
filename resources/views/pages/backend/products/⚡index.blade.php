<?php

use App\Actions\Products\DeleteProduct;
use App\Actions\Products\GetProductDetails;
use App\Actions\Products\GetProductPackages;
use App\Actions\Products\GetProducts;
use App\Actions\Products\ToggleProductStatus;
use App\Actions\Products\UpsertProduct;
use App\Enums\ProductAmountMode;
use App\Models\Product;
use App\Services\PriceCalculator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;

new class extends Component
{
    use WithPagination;

    public string $search = '';
    public string $statusFilter = 'all';
    public string $packageFilter = '';
    public string $sortBy = 'order';
    public string $sortDirection = 'asc';
    public int $perPage = 10;

    public ?int $editingProductId = null;
    public ?int $productPackageId = null;
    public ?string $productSerial = null;
    public string $productName = '';
    public ?string $productEntryPrice = null;
    public ?int $productOrder = null;
    public bool $productIsActive = true;

    public string $productAmountMode = 'fixed';

    public ?string $productAmountUnitLabel = null;

    public ?int $productCustomAmountMin = null;

    public ?int $productCustomAmountMax = null;

    public ?int $productCustomAmountStep = null;

    public ?string $productApi = null;

    public bool $showDeleteProductModal = false;
    public ?int $deleteProductId = null;
    public string $deleteProductName = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage_products'), 403);
    }

    public function applyFilters(): void
    {
        $this->resetPage();
    }

    public function resetFilters(): void
    {
        $this->reset(['search', 'statusFilter', 'packageFilter', 'sortBy', 'sortDirection', 'perPage']);
        $this->resetPage();
    }

    /**
     * @return array<string, mixed>
     */
    protected function productRules(): array
    {
        $isCustom = $this->productAmountMode === ProductAmountMode::Custom->value;

        return [
            'productPackageId' => ['required', 'integer', 'exists:packages,id'],
            'productSerial' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('products', 'serial')->ignore($this->editingProductId),
            ],
            'productName' => ['required', 'string', 'max:255'],
            'productAmountMode' => ['required', 'string', Rule::in(ProductAmountMode::values())],
            'productEntryPrice' => array_merge(
                ['required', 'numeric'],
                $isCustom ? ['gt:0'] : ['min:0'],
            ),
            'productAmountUnitLabel' => [
                Rule::requiredIf($isCustom),
                'nullable',
                'string',
                'max:64',
            ],
            'productCustomAmountMin' => [
                Rule::requiredIf($isCustom),
                'nullable',
                'integer',
                'min:1',
            ],
            'productCustomAmountMax' => [
                Rule::requiredIf($isCustom),
                'nullable',
                'integer',
                'min:1',
                'gte:productCustomAmountMin',
            ],
            'productCustomAmountStep' => ['nullable', 'integer', 'min:1'],
            'productOrder' => [
                'required',
                'integer',
                'min:0',
                Rule::unique('products', 'order')->ignore($this->editingProductId),
            ],
            'productIsActive' => ['boolean'],
            'productApi' => ['nullable', 'string', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function productValidationAttributes(): array
    {
        return [
            'productPackageId' => __('messages.package'),
            'productSerial' => __('messages.serial'),
            'productName' => __('messages.name'),
            'productEntryPrice' => __('messages.entry_price'),
            'productAmountMode' => __('messages.product_amount_mode'),
            'productAmountUnitLabel' => __('messages.amount_unit_label'),
            'productCustomAmountMin' => __('messages.custom_amount_min'),
            'productCustomAmountMax' => __('messages.custom_amount_max'),
            'productCustomAmountStep' => __('messages.custom_amount_step'),
            'productOrder' => __('messages.order'),
            'productIsActive' => __('messages.active'),
            'productApi' => __('messages.product_api'),
        ];
    }

    public function saveProduct(): void
    {
        $validated = $this->validate($this->productRules(), [], $this->productValidationAttributes());

        app(UpsertProduct::class)->handle(
            $this->editingProductId,
            [
                'package_id' => $validated['productPackageId'],
                'serial' => $validated['productSerial'],
                'name' => $validated['productName'],
                'entry_price' => $validated['productEntryPrice'],
                'is_active' => $validated['productIsActive'],
                'order' => $validated['productOrder'],
                'amount_mode' => $validated['productAmountMode'],
                'amount_unit_label' => $validated['productAmountUnitLabel'] ?? null,
                'custom_amount_min' => $validated['productCustomAmountMin'] ?? null,
                'custom_amount_max' => $validated['productCustomAmountMax'] ?? null,
                'custom_amount_step' => $validated['productCustomAmountStep'] ?? null,
                'product_api' => $validated['productApi'] ?? null,
            ]
        );

        $this->resetProductForm();
        $this->resetPage();
        $this->dispatch('product-saved');
    }

    public function startEditProduct(int $productId): void
    {
        $product = app(GetProductDetails::class)->handle($productId);

        if ($product === null) {
            return;
        }

        $this->editingProductId = $product->id;
        $this->productPackageId = $product->package_id;
        $this->productSerial = $product->serial;
        $this->productName = $product->name;
        $this->productEntryPrice = $product->entry_price !== null ? (string) $product->entry_price : null;
        $this->productOrder = $product->order;
        $this->productIsActive = $product->is_active;
        $this->productAmountMode = ($product->amount_mode ?? ProductAmountMode::Fixed)->value;
        $this->productAmountUnitLabel = $product->amount_unit_label;
        $this->productCustomAmountMin = $product->custom_amount_min;
        $this->productCustomAmountMax = $product->custom_amount_max;
        $this->productCustomAmountStep = $product->custom_amount_step;
        $this->productApi = $product->product_api;

        $this->dispatch('open-product-panel');
    }

    public function toggleStatus(int $productId): void
    {
        app(ToggleProductStatus::class)->handle($productId);
    }

    public function confirmDeleteProduct(int $productId): void
    {
        $product = app(GetProductDetails::class)->handle($productId);

        if ($product === null) {
            return;
        }

        $this->deleteProductId = $product->id;
        $this->deleteProductName = $product->name;
        $this->showDeleteProductModal = true;
    }

    public function cancelDeleteProduct(): void
    {
        $this->reset(['showDeleteProductModal', 'deleteProductId', 'deleteProductName']);
    }

    public function deleteProduct(?int $productId = null): void
    {
        $productId = $productId ?? $this->deleteProductId;

        if ($productId === null) {
            return;
        }

        app(DeleteProduct::class)->handle($productId, auth()->id());

        $this->cancelDeleteProduct();
        $this->resetPage();
    }

    public function resetProductForm(): void
    {
        $this->reset([
            'editingProductId',
            'productPackageId',
            'productSerial',
            'productName',
            'productEntryPrice',
            'productOrder',
            'productIsActive',
            'productAmountMode',
            'productAmountUnitLabel',
            'productCustomAmountMin',
            'productCustomAmountMax',
            'productCustomAmountStep',
            'productApi',
        ]);
        $this->productAmountMode = ProductAmountMode::Fixed->value;
        $this->resetValidation();
    }

    public function getProductsProperty(): LengthAwarePaginator
    {
        return app(GetProducts::class)->handle(
            $this->search,
            $this->statusFilter,
            $this->sortBy,
            $this->sortDirection,
            $this->perPage,
            $this->packageFilter !== '' ? (int) $this->packageFilter : null,
        );
    }

    public function getPackagesProperty(): Collection
    {
        return app(GetProductPackages::class)->handle();
    }

    /**
     * Admin preview uses extra scale so per-unit entry prices below 0.01 still show non-zero derived margins.
     * Checkout and Product accessors keep {@see PriceCalculator::calculate()} default scale (2).
     */
    private const DERIVED_PREVIEW_ROUNDING_SCALE = 6;

    public function formatDerivedPricePreview(?float $value): string
    {
        if ($value === null) {
            return '—';
        }

        $abs = abs($value);
        if ($abs < 1e-12) {
            return number_format(0.0, 2, '.', '');
        }

        if ($abs < 0.01) {
            return number_format($value, 6, '.', '');
        }

        return number_format($value, 2, '.', '');
    }

    public function formatEntryPriceForTable(Product $product): string
    {
        if ($product->entry_price === null) {
            return '—';
        }

        $value = (float) $product->entry_price;
        $isCustom = $product->amount_mode === ProductAmountMode::Custom;

        if (! $isCustom || abs($value) >= 0.01) {
            return number_format($value, 2, '.', '');
        }

        if (abs($value) < 1e-12) {
            return number_format(0.0, 2, '.', '');
        }

        return number_format($value, 6, '.', '');
    }

    /**
     * Live preview: derived retail price for current productEntryPrice (backend source of truth).
     */
    public function getComputedRetailPriceProperty(): ?float
    {
        $entry = $this->productEntryPrice;
        if ($entry === null || $entry === '' || ! is_numeric($entry) || (float) $entry < 0) {
            return null;
        }

        try {
            return app(PriceCalculator::class)->calculate((float) $entry, self::DERIVED_PREVIEW_ROUNDING_SCALE)['retail_price'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Live preview: derived wholesale price for current productEntryPrice (backend source of truth).
     */
    public function getComputedWholesalePriceProperty(): ?float
    {
        $entry = $this->productEntryPrice;
        if ($entry === null || $entry === '' || ! is_numeric($entry) || (float) $entry < 0) {
            return null;
        }

        try {
            return app(PriceCalculator::class)->calculate((float) $entry, self::DERIVED_PREVIEW_ROUNDING_SCALE)['wholesale_price'];
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Uses global min/max so the placeholder reflects actual order bounds.
     */
    public function getOrderRangePlaceholderProperty(): string
    {
        $min = Product::min('order');
        $max = Product::max('order');

        if ($min === null || $max === null) {
            return __('messages.no_orders_yet');
        }

        return $min.' - '.$max;
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.products'));
    }
};
?>

<div
    class="admin-products flex h-full w-full flex-1 flex-col gap-8"
    x-data="{
        showFilters: false,
        showProductForm: false,
        toggleProductForm() {
            if (this.showProductForm) {
                this.showProductForm = false;
                $wire.resetProductForm();
                return;
            }

            this.showProductForm = true;
        },
    }"
    x-on:product-saved.window="showProductForm = false"
    x-on:open-product-panel.window="showProductForm = true"
    data-test="products-page"
>
    <header class="cf-reveal relative grid gap-6 lg:grid-cols-[1fr_auto] lg:items-end">
        <div class="max-w-2xl space-y-3">
            <p class="cf-display text-xs font-semibold tracking-[0.2em] text-[var(--cf-primary)] uppercase">
                {{ __('messages.nav_content_management') }}
            </p>
            <flux:heading size="lg" class="cf-display text-3xl tracking-tight text-[var(--cf-foreground)] md:text-4xl">
                {{ __('messages.products') }}
            </flux:heading>
            <flux:text class="max-w-xl text-sm leading-relaxed text-[var(--cf-muted-foreground)]">
                {{ __('messages.products_intro') }}
            </flux:text>
        </div>
        <div
            class="hidden h-24 w-full max-w-xs skew-x-[-8deg] rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card-elevated)] opacity-90 lg:block"
            aria-hidden="true"
        >
            <div class="h-full w-full rounded-[inherit] bg-gradient-to-br from-[var(--cf-primary-soft)] to-transparent"></div>
        </div>
    </header>

    <div class="cf-reveal cf-reveal-delay-1 flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center">
            <div class="cf-icon-ring cf-icon-ring--cool shrink-0">
                <flux:icon icon="shopping-cart" class="size-6" />
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs text-[var(--cf-muted-foreground)]" role="status" aria-live="polite">
                <span>{{ __('messages.showing') }}</span>
                <span class="font-semibold tabular-nums text-[var(--cf-foreground)]">
                    {{ $this->products->count() }}
                </span>
                <span>{{ __('messages.of') }}</span>
                <span class="font-semibold tabular-nums text-[var(--cf-foreground)]">
                    {{ $this->products->total() }}
                </span>
                <span>{{ __('messages.products') }}</span>
                @if ($statusFilter !== 'all')
                    <flux:badge color="{{ $statusFilter === 'active' ? 'emerald' : 'amber' }}" size="sm" variant="subtle">
                        {{ $statusFilter === 'active' ? __('messages.active') : __('messages.inactive_status') }}
                    </flux:badge>
                @endif
            </div>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <flux:button
                type="button"
                variant="primary"
                icon="plus"
                class="!bg-[var(--cf-primary)] !text-[var(--cf-primary-foreground)] transition-colors duration-200 hover:brightness-110"
                x-on:click="toggleProductForm()"
                x-bind:aria-expanded="showProductForm"
                aria-controls="product-form-section"
            >
                {{ __('messages.new_product') }}
            </flux:button>
            <flux:button
                type="button"
                variant="outline"
                icon="adjustments-horizontal"
                class="border-[var(--cf-border)] text-[var(--cf-foreground)] transition-colors duration-200 hover:border-[color-mix(in_srgb,var(--cf-primary)_40%,var(--cf-border))] hover:bg-[var(--cf-primary-soft)]"
                x-on:click="showFilters = !showFilters"
                x-bind:aria-expanded="showFilters"
                aria-controls="products-filters"
            >
                {{ __('messages.filters') }}
            </flux:button>
            <flux:button
                type="button"
                variant="outline"
                icon="arrow-path"
                class="border-[var(--cf-border)] text-[var(--cf-muted-foreground)] transition-colors duration-200 hover:bg-[var(--cf-card-elevated)] hover:text-[var(--cf-foreground)]"
                wire:click="$refresh"
                wire:loading.attr="disabled"
            >
                {{ __('messages.refresh') }}
            </flux:button>
        </div>
    </div>

    <form
        id="products-filters"
        class="cf-reveal cf-reveal-delay-2 rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-6"
            wire:submit.prevent="applyFilters"
            x-show="showFilters"
            x-cloak
            data-test="products-filters"
            role="search"
            aria-label="{{ __('messages.filters') }}"
        >
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="grid gap-2">
                    <flux:input
                        name="search"
                        :label="__('messages.search')"
                        :placeholder="__('messages.search_placeholder')"
                        wire:model.defer="search"
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    />
                </div>
                <div class="grid gap-2">
                    <flux:select class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                 name="sortBy" label="{{ __('messages.sort_by') }}" wire:model.defer="sortBy">
                        <flux:select.option value="order">{{ __('messages.order') }}</flux:select.option>
                        <flux:select.option value="name">{{ __('messages.name') }}</flux:select.option>
                        <flux:select.option value="entry_price">{{ __('messages.entry_price') }}</flux:select.option>
                        <flux:select.option value="created_at">{{ __('messages.created') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="grid gap-2">
                    <flux:select class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                 name="sortDirection" label="{{ __('messages.direction') }}" wire:model.defer="sortDirection">
                        <flux:select.option value="asc">{{ __('messages.ascending') }}</flux:select.option>
                        <flux:select.option value="desc">{{ __('messages.descending') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="grid gap-2">
                    <flux:select class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                                 name="perPage" label="{{ __('messages.per_page') }}" wire:model.defer="perPage">
                        <flux:select.option value="10">10</flux:select.option>
                        <flux:select.option value="25">25</flux:select.option>
                        <flux:select.option value="50">50</flux:select.option>
                    </flux:select>
                </div>
            </div>
            <div class="mt-6 flex flex-row flex-wrap items-end gap-3">
                <div class="w-full min-w-0 sm:w-auto sm:min-w-[140px]">
                    <flux:select
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="statusFilter"
                        label="{{ __('messages.status') }}"
                        wire:model.defer="statusFilter"
                    >
                        <flux:select.option value="all">{{ __('messages.all') }}</flux:select.option>
                        <flux:select.option value="active">{{ __('messages.active') }}</flux:select.option>
                        <flux:select.option value="inactive">{{ __('messages.inactive_status') }}</flux:select.option>
                    </flux:select>
                </div>
                <div class="w-full min-w-0 sm:w-auto sm:min-w-[200px]">
                    <flux:select
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="packageFilter"
                        label="{{ __('messages.package') }}"
                        wire:model.defer="packageFilter"
                    >
                        <flux:select.option value="">{{ __('messages.all') }}</flux:select.option>
                        @foreach ($this->packages as $package)
                            <flux:select.option value="{{ $package->id }}">{{ $package->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
                <flux:button type="submit" variant="primary" icon="magnifying-glass" class="w-full sm:w-auto !bg-[var(--cf-primary)] !text-[var(--cf-primary-foreground)] transition-colors duration-200 hover:brightness-110">
                    {{ __('messages.apply') }}
                </flux:button>
                <flux:button type="button" variant="outline" icon="arrow-path" wire:click="resetFilters" class="w-full sm:w-auto border-[var(--cf-border)] text-[var(--cf-muted-foreground)] transition-colors duration-200 hover:bg-[var(--cf-card-elevated)] hover:text-[var(--cf-foreground)]">
                    {{ __('messages.reset') }}
                </flux:button>
            </div>
        </form>

    <section
        id="product-form-section"
        class="cf-reveal cf-reveal-delay-3 rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-6"
        x-show="showProductForm"
        x-cloak
        role="region"
        aria-labelledby="product-form-heading"
    >
        <form class="grid gap-5" wire:submit.prevent="saveProduct">
            <div class="flex flex-col gap-1">
                <flux:heading id="product-form-heading" size="sm" class="cf-display text-[var(--cf-foreground)]">
                    {{ $editingProductId ? __('messages.edit_product') : __('messages.create_product') }}
                </flux:heading>
                <flux:text class="text-[var(--cf-muted-foreground)]">
                    {{ $editingProductId ? __('messages.edit_product_hint') : __('messages.product_slug_auto') }}
                </flux:text>
            </div>

            <div class="grid gap-4 md:grid-cols-2">
                <div class="grid gap-2">
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="productName"
                        label="{{ __('messages.name') }}"
                        placeholder="{{ __('messages.product_name_placeholder') }}"
                        wire:model.defer="productName"
                    />
                    @error('productName')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="grid gap-2">
                    <flux:select
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="productPackageId"
                        label="{{ __('messages.package') }}"
                        wire:model.defer="productPackageId"
                        placeholder="{{ __('messages.select_package') }}"
                    >
                        <flux:select.option value="">{{ __('messages.select_package') }}</flux:select.option>
                        @foreach ($this->packages as $package)
                            <flux:select.option value="{{ $package->id }}">{{ $package->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('productPackageId')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="grid gap-2">
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="productSerial"
                        label="{{ __('messages.serial') }}"
                        placeholder="{{ __('messages.product_serial_placeholder') }}"
                        wire:model.defer="productSerial"
                    />
                    @error('productSerial')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="productApi"
                        label="{{ __('messages.product_api') }}"
                        placeholder="{{ __('messages.product_api_placeholder') }}"
                        wire:model.defer="productApi"
                    />
                    <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.product_api_hint') }}
                    </flux:text>
                    @error('productApi')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="grid gap-2">
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="productOrder"
                        label="{{ __('messages.order') }}"
                        type="number"
                        min="0"
                        step="1"
                        placeholder="{{ $this->orderRangePlaceholder }}"
                        wire:model.defer="productOrder"
                    />
                    @error('productOrder')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <flux:select
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="productAmountMode"
                        label="{{ __('messages.product_amount_mode') }}"
                        wire:model.live="productAmountMode"
                    >
                        <flux:select.option value="{{ ProductAmountMode::Fixed->value }}">{{ __('messages.amount_mode_fixed') }}</flux:select.option>
                        <flux:select.option value="{{ ProductAmountMode::Custom->value }}">{{ __('messages.amount_mode_custom') }}</flux:select.option>
                    </flux:select>
                    @error('productAmountMode')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                    <flux:text class="text-[var(--cf-muted-foreground)]">
                        {{ __('messages.product_amount_mode_hint') }}
                    </flux:text>
                </div>
                @if ($productAmountMode === ProductAmountMode::Custom->value)
                    <div class="grid gap-2 md:col-span-2">
                        <flux:input
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            name="productAmountUnitLabel"
                            label="{{ __('messages.amount_unit_label') }}"
                            placeholder="{{ __('messages.amount_unit_label_placeholder') }}"
                            wire:model.defer="productAmountUnitLabel"
                        />
                        @error('productAmountUnitLabel')
                            <flux:text color="red">{{ $message }}</flux:text>
                        @enderror
                    </div>
                    <div class="grid gap-2">
                        <flux:input
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            name="productCustomAmountMin"
                            label="{{ __('messages.custom_amount_min') }}"
                            type="number"
                            min="1"
                            step="1"
                            wire:model.defer="productCustomAmountMin"
                        />
                        @error('productCustomAmountMin')
                            <flux:text color="red">{{ $message }}</flux:text>
                        @enderror
                    </div>
                    <div class="grid gap-2">
                        <flux:input
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            name="productCustomAmountMax"
                            label="{{ __('messages.custom_amount_max') }}"
                            type="number"
                            min="1"
                            step="1"
                            wire:model.defer="productCustomAmountMax"
                        />
                        @error('productCustomAmountMax')
                            <flux:text color="red">{{ $message }}</flux:text>
                        @enderror
                    </div>
                    <div class="grid gap-2">
                        <flux:input
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            name="productCustomAmountStep"
                            label="{{ __('messages.custom_amount_step') }}"
                            type="number"
                            min="1"
                            step="1"
                            placeholder="{{ __('messages.custom_amount_step_placeholder') }}"
                            wire:model.defer="productCustomAmountStep"
                        />
                        @error('productCustomAmountStep')
                            <flux:text color="red">{{ $message }}</flux:text>
                        @enderror
                    </div>
                @endif
                <div class="grid gap-2">
                    <flux:input
                        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        name="productEntryPrice"
                        label="{{ __('messages.entry_price') }}"
                        type="number"
                        min="0"
                        step="any"
                        placeholder="{{ __('messages.entry_price_placeholder') }}"
                        wire:model.live="productEntryPrice"
                    />
                    @error('productEntryPrice')
                        <flux:text color="red">{{ $message }}</flux:text>
                    @enderror
                    <flux:text class="text-[var(--cf-muted-foreground)]">
                        {{ $productAmountMode === ProductAmountMode::Custom->value ? __('messages.entry_price_per_unit_hint') : __('messages.entry_price_fixed_hint') }}
                    </flux:text>
                </div>
                <div class="grid gap-2 md:col-span-2">
                    <span class="text-sm font-medium text-[var(--cf-foreground)]">{{ __('messages.derived_prices') }}</span>
                    <div class="flex flex-wrap gap-6 rounded-lg border border-[var(--cf-border)] bg-[var(--cf-background)] px-4 py-3">
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs text-[var(--cf-muted-foreground)]">{{ __('messages.retail_price') }}</span>
                            <span class="font-mono text-base text-[var(--cf-primary)] tabular-nums">
                                {{ $this->formatDerivedPricePreview($this->computedRetailPrice) }}
                            </span>
                        </div>
                        <div class="flex flex-col gap-0.5">
                            <span class="text-xs text-[var(--cf-muted-foreground)]">{{ __('messages.wholesale_price') }}</span>
                            <span class="font-mono text-base text-[var(--cf-foreground)] tabular-nums">
                                {{ $this->formatDerivedPricePreview($this->computedWholesalePrice) }}
                            </span>
                        </div>
                    </div>
                </div>
                <div class="flex items-center gap-3">
                    <flux:label>{{ __('messages.active') }}:</flux:label>
                    <flux:switch
                        class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                        wire:model.defer="productIsActive"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <flux:button
                    class="!bg-[var(--cf-primary)] !text-[var(--cf-primary-foreground)] transition-colors duration-200 hover:brightness-110 focus:!border-[var(--cf-primary)] focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    type="submit" variant="primary" icon="plus" wire:loading.attr="disabled" wire:target="saveProduct">
                    {{ $editingProductId ? __('messages.update_product') : __('messages.create_product') }}
                </flux:button>
                <flux:button
                    class="text-[var(--cf-muted-foreground)] hover:bg-[var(--cf-card-elevated)] hover:text-[var(--cf-foreground)] focus:!border-[var(--cf-primary)] focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                    type="button" variant="ghost" x-on:click="toggleProductForm()">
                    {{ __('messages.cancel') }}
                </flux:button>
            </div>
        </form>
    </section>

    <section class="cf-reveal cf-reveal-delay-4 cf-table-shell" aria-labelledby="products-table-heading">
        <div class="cf-table-head px-5 py-4">
            <flux:heading id="products-table-heading" size="sm" class="cf-display text-[var(--cf-foreground)]">
                {{ __('messages.products') }}
            </flux:heading>
        </div>

        <div
            class="overflow-x-auto"
            wire:loading.class="opacity-60"
            wire:target="applyFilters,resetFilters,$refresh,nextPage,previousPage,gotoPage"
        >
            <div
                class="p-6"
                wire:loading.delay
                wire:target="applyFilters,resetFilters,$refresh,nextPage,previousPage,gotoPage"
            >
                <div class="grid gap-3">
                    <flux:skeleton class="h-4 w-36" />
                    <div class="grid gap-2">
                        <flux:skeleton class="h-10 w-full" />
                        <flux:skeleton class="h-10 w-full" />
                        <flux:skeleton class="h-10 w-full" />
                        <flux:skeleton class="h-10 w-full" />
                        <flux:skeleton class="h-10 w-full" />
                    </div>
                </div>
            </div>
            <div wire:loading.delay.remove wire:target="applyFilters,resetFilters,$refresh,nextPage,previousPage,gotoPage">
                @if ($this->products->count() === 0)
                    <div class="flex flex-col items-center gap-3 p-10 text-center" role="status" aria-live="polite">
                        <div
                            class="flex size-12 items-center justify-center rounded-full border border-[var(--cf-border)] bg-[var(--cf-card-elevated)] text-[var(--cf-muted-foreground)]"
                            aria-hidden="true"
                        >
                            <flux:icon icon="shopping-cart" class="size-5" />
                        </div>
                        <div class="flex flex-col gap-1">
                            <flux:heading size="sm" class="cf-display text-[var(--cf-foreground)]">
                                {{ __('messages.no_products_yet') }}
                            </flux:heading>
                            <flux:text class="text-[var(--cf-muted-foreground)]">
                                {{ __('messages.create_first_product') }}
                            </flux:text>
                        </div>
                        <flux:button
                            type="button"
                            variant="primary"
                            icon="plus"
                            class="!bg-[var(--cf-primary)] !text-[var(--cf-primary-foreground)] transition-colors duration-200 hover:brightness-110"
                            x-on:click="showProductForm = true"
                        >
                            {{ __('messages.add_product') }}
                        </flux:button>
                    </div>
                @else
                    <table class="min-w-full divide-y divide-[var(--cf-border)] text-sm" data-test="products-table">
                        <thead class="bg-[var(--cf-card)] text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-start font-semibold">{{ __('messages.product') }}</th>
                                <th scope="col" class="px-5 py-3 text-start font-semibold hidden sm:table-cell">{{ __('messages.package') }}</th>
                                <th scope="col" class="px-5 py-3 text-start font-semibold">{{ __('messages.entry_price') }}</th>
                                <th scope="col" class="px-5 py-3 text-start font-semibold">{{ __('messages.retail_price') }}</th>
                                <th scope="col" class="px-5 py-3 text-start font-semibold hidden lg:table-cell">{{ __('messages.wholesale_price') }}</th>
                                <th scope="col" class="px-5 py-3 text-start font-semibold hidden xl:table-cell">{{ __('messages.order') }}</th>
                                <th scope="col" class="px-5 py-3 text-start font-semibold">{{ __('messages.status') }}</th>
                                <th scope="col" class="px-5 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--cf-border)]">
                            @foreach ($this->products as $product)
                                <tr
                                    class="transition-colors duration-200 hover:bg-[var(--cf-card-elevated)]"
                                    wire:key="product-{{ $product->id }}"
                                >
                                    <td class="px-5 py-4">
                                        <div class="min-w-0">
                                            <div class="flex flex-wrap items-center gap-2">
                                                <span class="truncate font-semibold text-[var(--cf-foreground)]">
                                                    {{ $product->name }}
                                                </span>
                                                @if ($product->serial)
                                                    <flux:badge color="zinc" size="sm" variant="subtle">{{ $product->serial }}</flux:badge>
                                                @endif
                                                @if ($product->amount_mode === ProductAmountMode::Custom)
                                                    <flux:badge color="sky" size="sm" variant="subtle">
                                                        {{ __('messages.amount_mode_custom') }}
                                                        @if (filled($product->amount_unit_label))
                                                            <span class="font-normal opacity-90">· {{ $product->amount_unit_label }}</span>
                                                        @endif
                                                    </flux:badge>
                                                @endif
                                            </div>
                                            <div class="text-xs text-[var(--cf-muted-foreground)]">
                                                @if ($product->package)
                                                    /{{ $product->package->slug }}/{{ $product->slug }}
                                                @else
                                                    /{{ $product->slug }}
                                                @endif
                                            </div>
                                            @if ($product->amount_mode === ProductAmountMode::Custom)
                                                <div class="text-xs text-[var(--cf-muted-foreground)]">
                                                    {{ __('messages.custom_amount_min') }}: {{ $product->custom_amount_min ?? '—' }}
                                                    · {{ __('messages.custom_amount_max') }}: {{ $product->custom_amount_max ?? '—' }}
                                                    @if ($product->custom_amount_step !== null)
                                                        · {{ __('messages.custom_amount_step') }}: {{ $product->custom_amount_step }}
                                                    @endif
                                                </div>
                                            @endif
                                            @if ($product->package)
                                                <div class="text-xs text-[var(--cf-muted-foreground)] sm:hidden">
                                                    {{ $product->package->name }}
                                                </div>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="hidden px-5 py-4 text-[var(--cf-muted-foreground)] sm:table-cell">
                                        {{ $product->package?->name ?? __('messages.no_package') }}
                                    </td>
                                    <td class="px-5 py-4 text-end text-[var(--cf-foreground)] tabular-nums">
                                        {{ $this->formatEntryPriceForTable($product) }}
                                    </td>
                                    <td class="px-5 py-4 text-end text-[var(--cf-foreground)] tabular-nums">
                                        {{ $this->formatDerivedPricePreview((float) $product->retail_price) }}
                                        <div class="mt-0.5 text-left text-xs text-[var(--cf-muted-foreground)] lg:hidden">
                                            {{ __('messages.wholesale_price') }} {{ $this->formatDerivedPricePreview((float) $product->wholesale_price) }}
                                        </div>
                                    </td>
                                    <td class="hidden px-5 py-4 text-end text-[var(--cf-foreground)] tabular-nums lg:table-cell">
                                        {{ $this->formatDerivedPricePreview((float) $product->wholesale_price) }}
                                    </td>
                                    <td class="hidden px-5 py-4 text-[var(--cf-muted-foreground)] xl:table-cell">
                                        {{ $product->order ?? '—' }}
                                    </td>
                                    <td class="px-5 py-4">
                                        <div class="flex items-center justify-start">
                                            <flux:switch
                                                :checked="$product->is_active"
                                                wire:click="toggleStatus({{ $product->id }})"
                                                aria-label="{{ __('messages.toggle_status_for', ['name' => $product->name]) }}"
                                            />
                                        </div>
                                    </td>
                                    <td class="px-5 py-4 text-end">
                                        <flux:dropdown position="bottom" align="end">
                                            <flux:button
                                                variant="ghost"
                                                icon="ellipsis-vertical"
                                                class="text-[var(--cf-muted-foreground)] hover:bg-[var(--cf-card-elevated)] hover:text-[var(--cf-foreground)]"
                                            />
                                            <flux:menu>
                                                <flux:menu.item icon="pencil" wire:click="startEditProduct({{ $product->id }})">
                                                    {{ __('messages.edit') }}
                                                </flux:menu.item>
                                                <flux:menu.item icon="eye">{{ __('messages.view') }}</flux:menu.item>
                                                <flux:menu.separator />
                                                <flux:menu.item
                                                    variant="danger"
                                                    icon="trash"
                                                    wire:click="confirmDeleteProduct({{ $product->id }})"
                                                >
                                                    {{ __('messages.delete') }}
                                                </flux:menu.item>
                                            </flux:menu>
                                        </flux:dropdown>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>
        </div>

        <div class="cf-pagination border-t border-[var(--cf-border)] px-5 py-4">
            {{ $this->products->links() }}
        </div>
    </section>

    <flux:modal
        wire:model.self="showDeleteProductModal"
        class="admin-themed-modal max-w-md"
        variant="floating"
        @close="cancelDeleteProduct"
        @cancel="cancelDeleteProduct"
    >
        <div class="space-y-6 text-[var(--cf-foreground)]">
            <div class="flex items-start gap-4">
                <div
                    class="flex size-11 shrink-0 items-center justify-center rounded-full border border-[var(--cf-border)] bg-[var(--cf-destructive-soft)] text-[var(--cf-destructive)]"
                >
                    <flux:icon icon="trash" class="size-5" />
                </div>
                <div class="space-y-2">
                    <flux:heading size="lg" class="cf-display text-[var(--cf-foreground)]">
                        {{ __('messages.delete_product_title') }}
                    </flux:heading>
                    <flux:text class="text-[var(--cf-muted-foreground)]">
                        {{ __('messages.delete_product_body', ['name' => $deleteProductName]) }}
                    </flux:text>
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <div class="grow" aria-hidden="true"></div>
                <flux:button
                    variant="ghost"
                    class="text-[var(--cf-muted-foreground)] hover:bg-[var(--cf-card-elevated)] hover:text-[var(--cf-foreground)]"
                    wire:click="cancelDeleteProduct"
                >
                    {{ __('messages.cancel') }}
                </flux:button>
                <flux:button
                    variant="danger"
                    wire:click="deleteProduct"
                    wire:loading.attr="disabled"
                    wire:target="deleteProduct"
                >
                    {{ __('messages.delete') }}
                </flux:button>
            </div>
        </div>
    </flux:modal>
</div>
