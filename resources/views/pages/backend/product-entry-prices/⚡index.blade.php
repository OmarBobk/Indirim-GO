<?php

use App\Actions\Products\GetProductPackages;
use App\Actions\Products\UpdateProductEntryPrice;
use App\Enums\ProductAmountMode;
use App\Models\Package;
use App\Models\Product;
use App\Services\SupplierPriceScanService;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

new class extends Component
{
    use Toastable;

    public string $packageId = '';

    /** @var array<string, string> */
    public array $newPrices = [];

    /** @var array<string, string> */
    public array $initialPrices = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('update_product_prices'), 403);
    }

    public function updatedPackageId(): void
    {
        $this->syncPriceRowsFromPackage();
    }

    public function getPackagesProperty(): Collection
    {
        return app(GetProductPackages::class)->handle();
    }

    public function getProductsForPackageProperty(): Collection
    {
        $id = $this->selectedPackageId();
        if ($id === null) {
            return collect();
        }

        return Product::query()
            ->where('package_id', $id)
            ->orderBy('order')
            ->orderBy('id')
            ->get();
    }

    public function getIsWasimPackageProperty(): bool
    {
        $id = $this->selectedPackageId();

        if ($id === null) {
            return false;
        }

        $provider = Package::query()->whereKey($id)->value('fulfillment_provider');

        return is_string($provider) && $provider === 'browser:wasim';
    }

    public function formatWasimScannedPrice(Product $product): string
    {
        if ($product->supplier_scan_error !== null) {
            return __('messages.price_drift_scan_error_short', ['code' => $product->supplier_scan_error]);
        }

        return app(SupplierPriceScanService::class)->formatScannedPrice($product)
            ?? __('messages.price_drift_never_scanned');
    }

    public function wasimProductUrl(Product $product): ?string
    {
        return app(SupplierPriceScanService::class)->buildWasimProductUrl($product);
    }

    public function saveChangedPrices(): void
    {
        if ($this->selectedPackageId() === null) {
            return;
        }

        $rules = [];
        $attributes = [];

        foreach ($this->productsForPackage as $product) {
            $id = (string) $product->id;
            $next = trim((string) ($this->newPrices[$id] ?? ''));
            $prev = trim((string) ($this->initialPrices[$id] ?? ''));

            if ($next === $prev) {
                continue;
            }

            $key = "newPrices.{$id}";
            $isCustom = $product->amount_mode === ProductAmountMode::Custom;
            $rules[$key] = array_merge(
                ['required', 'numeric'],
                $isCustom ? ['gt:0'] : ['min:0'],
            );
            $attributes[$key] = __('messages.entry_price').' — '.$product->name;
        }

        if ($rules === []) {
            $this->info(__('messages.product_entry_prices_no_changes'));

            return;
        }

        $this->validate($rules, [], $attributes);

        foreach ($this->productsForPackage as $product) {
            $id = (string) $product->id;
            $next = trim((string) ($this->newPrices[$id] ?? ''));
            $prev = trim((string) ($this->initialPrices[$id] ?? ''));

            if ($next === $prev) {
                continue;
            }

            app(UpdateProductEntryPrice::class)->handle($product, $next);
        }

        $this->syncPriceRowsFromPackage();
        $this->success(__('messages.product_entry_prices_updated'));
    }

    /**
     * Exact stored entry_price for display/inputs (matches DECIMAL / decimal:8; avoids float + 6dp rounding).
     */
    public function formatEntryPriceDisplay(Product $product): string
    {
        $stored = $this->formatStoredEntryPrice($product);

        return $stored ?? '—';
    }

    public function formatStoredEntryPrice(Product $product): ?string
    {
        $raw = $product->getRawOriginal('entry_price');
        if ($raw === null || $raw === '') {
            return null;
        }

        return $this->normalizeStoredDecimalString((string) $raw);
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.product_entry_prices'));
    }

    private function selectedPackageId(): ?int
    {
        return $this->packageId === '' ? null : (int) $this->packageId;
    }

    private function syncPriceRowsFromPackage(): void
    {
        $this->initialPrices = [];
        $this->newPrices = [];

        foreach ($this->productsForPackage as $product) {
            $id = (string) $product->id;
            $forInput = $this->formatStoredEntryPrice($product) ?? '';
            $this->initialPrices[$id] = $forInput;
            $this->newPrices[$id] = $forInput;
        }
    }

    private function normalizeStoredDecimalString(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+');
        if ($negative) {
            $value = ltrim($value, '-');
        }

        if (! str_contains($value, '.')) {
            return ($negative ? '-' : '').$value;
        }

        [$int, $frac] = explode('.', $value, 2);
        $frac = rtrim($frac, '0');
        $normalized = $frac === '' ? $int : "{$int}.{$frac}";

        return ($negative ? '-' : '').$normalized;
    }
};
?>

<div
    class="admin-products flex h-full min-w-0 w-full flex-1 flex-col gap-8"
    x-data="{
        showHint: false,
        init() {
            this.showHint = true;
            setTimeout(() => (this.showHint = false), 5200);
        },
    }"
    data-test="product-entry-prices-page"
>
    <header class="cf-reveal relative grid gap-6 lg:grid-cols-[1fr_auto] lg:items-end">
        <div class="max-w-2xl space-y-3">
            <p class="cf-display text-xs font-semibold tracking-[0.2em] text-[var(--cf-primary)] uppercase">
                {{ __('messages.nav_content_management') }}
            </p>
            <flux:heading size="lg" class="cf-display text-3xl tracking-tight text-[var(--cf-foreground)] md:text-4xl">
                {{ __('messages.product_entry_prices') }}
            </flux:heading>
            <flux:text class="max-w-xl text-sm leading-relaxed text-[var(--cf-muted-foreground)]">
                {{ __('messages.product_entry_prices_intro') }}
            </flux:text>
        </div>
        <div
            class="hidden h-24 w-full max-w-xs skew-x-[-8deg] rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card-elevated)] opacity-90 lg:block"
            aria-hidden="true"
        >
            <div class="h-full w-full rounded-[inherit] bg-gradient-to-br from-[var(--cf-primary-soft)] to-transparent"></div>
        </div>
    </header>

    <section
        class="cf-reveal cf-reveal-delay-1 cf-table-shell border border-[var(--cf-border)] p-5"
        x-show="true"
        x-transition:enter="transition duration-300 ease-out"
        x-transition:enter-start="opacity-0 translate-y-2"
        x-transition:enter-end="opacity-100 translate-y-0"
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
            <div class="min-w-0 max-w-md flex-1">
                <flux:select
                    wire:model.live="packageId"
                    :label="__('messages.package')"
                    class="w-full"
                >
                    <flux:select.option value="">{{ __('messages.product_entry_prices_select_package') }}</flux:select.option>
                    @foreach ($this->packages as $pkg)
                        <flux:select.option value="{{ $pkg->id }}">{{ $pkg->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:button
                type="button"
                variant="primary"
                icon="check"
                class="!bg-[var(--cf-primary)] !text-[var(--cf-primary-foreground)] transition-colors duration-200 hover:brightness-110 disabled:opacity-40"
                wire:click="saveChangedPrices"
                wire:loading.attr="disabled"
                wire:target="saveChangedPrices"
                x-bind:disabled="! $wire.packageId || {{ $this->productsForPackage->isEmpty() ? 'true' : 'false' }}"
            >
                {{ __('messages.product_entry_prices_save') }}
            </flux:button>
        </div>

        <div
            x-show="showHint"
            x-transition.opacity.duration.300ms
            class="mt-4 rounded-lg border border-[var(--cf-muted-surface-border)] bg-[var(--cf-muted-surface)] px-4 py-3 text-xs text-[var(--cf-muted-foreground)]"
            role="status"
        >
            {{ __('messages.entry_price_fixed_hint') }}
            <span class="text-[var(--cf-foreground)]">·</span>
            {{ __('messages.entry_price_per_unit_hint') }}
        </div>

        @if ($this->packageId !== '' && $this->productsForPackage->isEmpty())
            <flux:callout class="mt-6 border-[var(--cf-border)] bg-[var(--cf-card)] text-[var(--cf-foreground)]" variant="neutral" icon="information-circle">
                {{ __('messages.no_products_yet') }}
            </flux:callout>
        @endif

        @if ($this->productsForPackage->isNotEmpty())
            <div class="mt-6 space-y-4">
                @foreach ($this->productsForPackage as $product)
                    <div
                        wire:key="pep-row-{{ $product->id }}"
                        class="cf-reveal grid gap-4 rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-4 md:grid-cols-2 md:gap-6"
                        style="animation-delay: {{ min($loop->index * 0.04, 0.36) }}s"
                    >
                        <div class="space-y-3">
                            <p class="cf-display text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--cf-primary)]">
                                {{ __('messages.product_entry_prices_current') }}
                            </p>
                            <flux:input :label="__('messages.name')" :value="$product->name" disabled readonly class:input="opacity-90" />
                            <div class="grid gap-3 sm:grid-cols-2">
                                <flux:input :label="__('messages.id')" :value="(string) $product->id" disabled readonly class:input="opacity-90" />
                                <flux:input
                                    :label="__('messages.entry_price')"
                                    :value="$this->formatEntryPriceDisplay($product)"
                                    disabled
                                    readonly
                                    class:input="opacity-90"
                                />
                            </div>
                            @if ($this->isWasimPackage)
                                <div class="space-y-1 rounded-lg border border-[var(--cf-border)] bg-[var(--cf-muted-surface)] px-3 py-2">
                                    <flux:text class="text-[10px] font-semibold uppercase tracking-[0.16em] text-[var(--cf-muted-foreground)]">
                                        {{ __('messages.product_entry_prices_wasim') }}
                                    </flux:text>
                                    <p class="tabular-nums text-sm text-[var(--cf-foreground)]">{{ $this->formatWasimScannedPrice($product) }}</p>
                                    @if ($url = $this->wasimProductUrl($product))
                                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-xs text-[var(--cf-primary)] hover:underline">
                                            {{ __('messages.product_entry_prices_open_wasim') }}
                                        </a>
                                    @endif
                                </div>
                            @endif
                        </div>
                        <div class="space-y-3 border-t border-[var(--cf-border)] pt-4 md:border-s md:border-t-0 md:pt-0 md:ps-6">
                            <p class="cf-display text-[10px] font-semibold uppercase tracking-[0.18em] text-[var(--cf-muted-foreground)]">
                                {{ __('messages.product_entry_prices_new') }}
                            </p>
                            <flux:input
                                type="text"
                                inputmode="decimal"
                                :label="__('messages.entry_price')"
                                :placeholder="__('messages.entry_price_placeholder')"
                                wire:model.defer="newPrices.{{ $product->id }}"
                                class:input="border-[var(--cf-border)] bg-[var(--cf-card-elevated)] text-[var(--cf-foreground)] focus:border-[var(--cf-ring)]"
                            />
                            <flux:text class="text-xs text-[var(--cf-muted-foreground)]">
                                {{ $product->amount_mode === \App\Enums\ProductAmountMode::Custom
                                    ? __('messages.entry_price_per_unit_hint')
                                    : __('messages.entry_price_fixed_hint') }}
                            </flux:text>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
