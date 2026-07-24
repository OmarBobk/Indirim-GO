<?php

use App\Actions\Products\GetProductPackages;
use App\Actions\SupplierPrices\ApplyWasimScannedEntryPrices;
use App\Actions\SupplierPrices\StartSupplierPriceScan;
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

    public string $filter = 'drifted';

    /** @var list<string> */
    public array $selected = [];

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('update_product_prices'), 403);
    }

    public function updatedPackageId(): void
    {
        $this->selected = [];
    }

    public function updatedFilter(): void
    {
        $this->selected = [];
    }

    public function getStatsProperty(): array
    {
        return app(SupplierPriceScanService::class)->monitorStats($this->selectedPackageId());
    }

    public function getScanRunningProperty(): bool
    {
        return (bool) ($this->stats['scan_running'] ?? false);
    }

    public function getProductsProperty(): Collection
    {
        return app(SupplierPriceScanService::class)
            ->monitorProductsQuery($this->selectedPackageId(), $this->filter)
            ->get();
    }

    public function getWasimPackagesProperty(): Collection
    {
        return Package::query()
            ->where('fulfillment_provider', 'browser:wasim')
            ->orderBy('order')
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    public function startScan(): void
    {
        try {
            app(StartSupplierPriceScan::class)->handle(
                $this->selectedPackageId(),
                null,
                'admin_ui',
            );
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $this->selected = [];
        $this->success(__('messages.price_drift_scan_started'));
    }

    public function applyWasimPrice(int $productId): void
    {
        try {
            app(ApplyWasimScannedEntryPrices::class)->handleOne($productId);
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return;
        }

        $this->selected = array_values(array_filter(
            $this->selected,
            static fn (string $id): bool => (int) $id !== $productId,
        ));

        $this->success(__('messages.price_drift_applied_one'));
    }

    public function applySelected(): void
    {
        $ids = array_values(array_unique(array_map(
            static fn (string|int $id): int => (int) $id,
            $this->selected,
        )));

        if ($ids === []) {
            $this->info(__('messages.price_drift_nothing_selected'));

            return;
        }

        $updated = app(ApplyWasimScannedEntryPrices::class)->handle($ids);

        if ($updated === 0) {
            $this->info(__('messages.price_drift_nothing_to_apply'));

            return;
        }

        $this->selected = [];
        $this->success(__('messages.price_drift_applied_count', ['count' => $updated]));
    }

    public function toggleSelectAll(): void
    {
        $applicableIds = $this->products
            ->filter(fn (Product $product): bool => $this->canApplyProduct($product))
            ->pluck('id')
            ->map(static fn (int $id): string => (string) $id)
            ->all();

        if ($applicableIds === []) {
            return;
        }

        $allSelected = count(array_intersect($applicableIds, $this->selected)) === count($applicableIds);

        $this->selected = $allSelected ? [] : $applicableIds;
    }

    public function canApplyProduct(Product $product): bool
    {
        return $product->supplier_scanned_price !== null
            && $product->supplier_scan_error === null
            && app(SupplierPriceScanService::class)->hasPriceDrift($product);
    }

    public function formatEntryPrice(Product $product): string
    {
        return app(SupplierPriceScanService::class)->formatEntryPrice($product) ?? '—';
    }

    public function formatScannedPrice(Product $product): string
    {
        if ($product->supplier_scan_error !== null) {
            return __('messages.price_drift_scan_error_short', ['code' => $product->supplier_scan_error]);
        }

        return app(SupplierPriceScanService::class)->formatScannedPrice($product)
            ?? __('messages.price_drift_never_scanned');
    }

    public function formatDriftPercent(Product $product): string
    {
        $percent = app(SupplierPriceScanService::class)->driftPercent($product);

        if ($percent === null) {
            return '—';
        }

        $sign = $percent > 0 ? '+' : '';

        return $sign.number_format($percent, 2).'%';
    }

    public function wasimProductUrl(Product $product): ?string
    {
        return app(SupplierPriceScanService::class)->buildWasimProductUrl($product);
    }

    public function flagReasonLabel(Product $product): ?string
    {
        return app(SupplierPriceScanService::class)->flagReasonLabel($product);
    }

    public function hasReactiveFlag(Product $product): bool
    {
        return app(SupplierPriceScanService::class)->hasReactiveFlag($product);
    }

    public function driftRowClass(Product $product): string
    {
        if ($product->supplier_scan_error !== null) {
            return 'border-amber-300/60 bg-amber-50/40 dark:border-amber-800/50 dark:bg-amber-950/20';
        }

        if (! app(SupplierPriceScanService::class)->hasPriceDrift($product)) {
            return 'border-[var(--cf-border)] bg-[var(--cf-card)]';
        }

        $percent = app(SupplierPriceScanService::class)->driftPercent($product);

        if ($percent !== null && $percent > 0) {
            return 'border-red-300/70 bg-red-50/50 dark:border-red-900/50 dark:bg-red-950/25';
        }

        return 'border-amber-300/60 bg-amber-50/40 dark:border-amber-800/50 dark:bg-amber-950/20';
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.price_drift'));
    }

    private function selectedPackageId(): ?int
    {
        return $this->packageId === '' ? null : (int) $this->packageId;
    }
};
?>

<div
    class="admin-price-drift flex h-full min-w-0 w-full flex-1 flex-col gap-8"
    data-test="price-drift-page"
    @if ($this->scanRunning) wire:poll.5s.visible @endif
>
    <header class="cf-reveal relative grid gap-6 lg:grid-cols-[1fr_auto] lg:items-end">
        <div class="max-w-2xl space-y-3">
            <p class="cf-display text-xs font-semibold tracking-[0.2em] text-[var(--cf-primary)] uppercase">
                {{ __('messages.nav_content_management') }}
            </p>
            <flux:heading size="lg" class="cf-display text-3xl tracking-tight text-[var(--cf-foreground)] md:text-4xl">
                {{ __('messages.price_drift') }}
            </flux:heading>
            <flux:text class="max-w-xl text-sm leading-relaxed text-[var(--cf-muted-foreground)]">
                {{ __('messages.price_drift_intro') }}
            </flux:text>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <flux:button
                type="button"
                variant="primary"
                icon="arrow-path"
                wire:click="startScan"
                wire:loading.attr="disabled"
                wire:target="startScan"
                :disabled="$this->scanRunning"
            >
                <span wire:loading.remove wire:target="startScan">{{ __('messages.price_drift_scan_now') }}</span>
                <span wire:loading wire:target="startScan">{{ __('messages.price_drift_scanning') }}</span>
            </flux:button>
            @if ($this->scanRunning)
                <flux:badge color="amber">{{ __('messages.price_drift_scan_in_progress') }}</flux:badge>
            @endif
        </div>
    </header>

  <section class="cf-reveal cf-reveal-delay-1 grid gap-3 sm:grid-cols-2 xl:grid-cols-6">
        <div class="rounded-xl border border-red-200 bg-red-50/80 p-4 dark:border-red-900/50 dark:bg-red-950/30">
            <div class="text-2xl font-bold tabular-nums text-red-700 dark:text-red-200">{{ $this->stats['drifted'] }}</div>
            <div class="text-xs font-medium text-red-800/80 dark:text-red-300/80">{{ __('messages.price_drift_kpi_drifted') }}</div>
        </div>
        <div class="rounded-xl border border-violet-200 bg-violet-50/80 p-4 dark:border-violet-900/50 dark:bg-violet-950/30">
            <div class="text-2xl font-bold tabular-nums text-violet-700 dark:text-violet-200">{{ $this->stats['flagged'] }}</div>
            <div class="text-xs font-medium text-violet-800/80 dark:text-violet-300/80">{{ __('messages.price_drift_kpi_flagged') }}</div>
        </div>
        <div class="rounded-xl border border-emerald-200 bg-emerald-50/80 p-4 dark:border-emerald-900/50 dark:bg-emerald-950/30">
            <div class="text-2xl font-bold tabular-nums text-emerald-700 dark:text-emerald-200">{{ $this->stats['unchanged'] }}</div>
            <div class="text-xs font-medium text-emerald-800/80 dark:text-emerald-300/80">{{ __('messages.price_drift_kpi_unchanged') }}</div>
        </div>
        <div class="rounded-xl border border-amber-200 bg-amber-50/80 p-4 dark:border-amber-900/50 dark:bg-amber-950/30">
            <div class="text-2xl font-bold tabular-nums text-amber-700 dark:text-amber-200">{{ $this->stats['errors'] }}</div>
            <div class="text-xs font-medium text-amber-800/80 dark:text-amber-300/80">{{ __('messages.price_drift_kpi_errors') }}</div>
        </div>
        <div class="rounded-xl border border-zinc-200 bg-zinc-50/80 p-4 dark:border-zinc-700 dark:bg-zinc-900/50">
            <div class="text-2xl font-bold tabular-nums text-zinc-700 dark:text-zinc-200">{{ $this->stats['never_scanned'] }}</div>
            <div class="text-xs font-medium text-zinc-600 dark:text-zinc-400">{{ __('messages.price_drift_kpi_never_scanned') }}</div>
        </div>
        <div class="rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card)] p-4">
            <div class="text-sm font-semibold text-[var(--cf-foreground)]">
                {{ $this->stats['last_scan_at'] ? $this->stats['last_scan_at']->timezone(config('app.timezone'))->format('M j, H:i') : '—' }}
            </div>
            <div class="text-xs text-[var(--cf-muted-foreground)]">{{ __('messages.price_drift_kpi_last_scan') }}</div>
        </div>
    </section>

    <section class="cf-reveal cf-reveal-delay-2 cf-table-shell border border-[var(--cf-border)] p-5">
        <div class="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
            <div class="grid flex-1 gap-4 sm:grid-cols-2 lg:max-w-2xl">
                <flux:select wire:model.live="packageId" :label="__('messages.package')">
                    <flux:select.option value="">{{ __('messages.price_drift_all_wasim_packages') }}</flux:select.option>
                    @foreach ($this->wasimPackages as $pkg)
                        <flux:select.option value="{{ $pkg->id }}">{{ $pkg->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="filter" :label="__('messages.filter')">
                    <flux:select.option value="drifted">{{ __('messages.price_drift_filter_drifted') }}</flux:select.option>
                    <flux:select.option value="flagged">{{ __('messages.price_drift_filter_flagged') }}</flux:select.option>
                    <flux:select.option value="unchanged">{{ __('messages.price_drift_filter_unchanged') }}</flux:select.option>
                    <flux:select.option value="errors">{{ __('messages.price_drift_filter_errors') }}</flux:select.option>
                    <flux:select.option value="never_scanned">{{ __('messages.price_drift_filter_never_scanned') }}</flux:select.option>
                    <flux:select.option value="all">{{ __('messages.all') }}</flux:select.option>
                </flux:select>
            </div>
            <flux:button
                type="button"
                variant="primary"
                icon="check"
                wire:click="applySelected"
                wire:loading.attr="disabled"
                wire:target="applySelected"
                x-bind:disabled="!$wire.selected || $wire.selected.length === 0"
            >
                {{ __('messages.price_drift_apply_selected') }}
            </flux:button>
        </div>

        @if ($this->products->isEmpty())
            <flux:callout class="mt-6" variant="neutral" icon="information-circle">
                {{ __('messages.price_drift_empty') }}
            </flux:callout>
        @else
            <div class="mt-6 overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="border-b border-[var(--cf-border)] text-start text-xs uppercase tracking-wide text-[var(--cf-muted-foreground)]">
                            <th class="px-3 py-2">
                                <flux:button size="xs" variant="ghost" type="button" wire:click="toggleSelectAll">
                                    {{ __('messages.all') }}
                                </flux:button>
                            </th>
                            <th class="px-3 py-2">{{ __('messages.product') }}</th>
                            <th class="px-3 py-2">{{ __('messages.package') }}</th>
                            <th class="px-3 py-2">{{ __('messages.entry_price') }}</th>
                            <th class="px-3 py-2">{{ __('messages.price_drift_wasim_price') }}</th>
                            <th class="px-3 py-2">{{ __('messages.price_drift_delta') }}</th>
                            <th class="px-3 py-2 text-end">{{ __('messages.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-[var(--cf-border)]">
                        @foreach ($this->products as $product)
                            <tr
                                wire:key="price-drift-row-{{ $product->id }}"
                                class="{{ $this->driftRowClass($product) }}"
                            >
                                <td class="px-3 py-3 align-middle">
                                    @if ($this->canApplyProduct($product))
                                        <flux:checkbox
                                            wire:key="price-drift-select-{{ $product->id }}"
                                            wire:model.live="selected"
                                            :value="$product->id"
                                            :label="false"
                                        />
                                    @endif
                                </td>
                                <td class="px-3 py-3 align-middle">
                                    <div class="font-medium text-[var(--cf-foreground)]">{{ $product->name }}</div>
                                    <div class="text-xs text-[var(--cf-muted-foreground)]">#{{ $product->id }}</div>
                                    @if ($flag = $this->flagReasonLabel($product))
                                        <flux:badge size="sm" color="violet" class="mt-1">{{ $flag }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-3 py-3 align-middle text-[var(--cf-muted-foreground)]">
                                    {{ $product->package?->name ?? '—' }}
                                </td>
                                <td class="px-3 py-3 align-middle tabular-nums">{{ $this->formatEntryPrice($product) }}</td>
                                <td class="px-3 py-3 align-middle tabular-nums">
                                    <div>{{ $this->formatScannedPrice($product) }}</div>
                                    @if ($url = $this->wasimProductUrl($product))
                                        <a href="{{ $url }}" target="_blank" rel="noopener noreferrer" class="text-xs text-[var(--cf-primary)] hover:underline">
                                            {{ __('messages.price_drift_open_wasim') }}
                                        </a>
                                    @endif
                                </td>
                                <td class="px-3 py-3 align-middle tabular-nums font-medium">
                                    {{ $this->formatDriftPercent($product) }}
                                </td>
                                <td class="px-3 py-3 align-middle text-end">
                                    @if ($this->canApplyProduct($product))
                                        <flux:button
                                            size="sm"
                                            variant="ghost"
                                            wire:click="applyWasimPrice({{ $product->id }})"
                                            wire:loading.attr="disabled"
                                            wire:target="applyWasimPrice({{ $product->id }})"
                                        >
                                            {{ __('messages.price_drift_apply') }}
                                        </flux:button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
