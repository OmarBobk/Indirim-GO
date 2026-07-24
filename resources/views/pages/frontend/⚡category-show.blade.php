<?php

declare(strict_types=1);

use App\Models\Category;
use App\Models\Package;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::frontend')] class extends Component
{
    public Category $category;

    public string $placeholderImage;

    public function mount(Category $category): void
    {
        abort_if(! $category->is_active, 404);

        $this->category = $category;
        $this->placeholderImage = asset('images/icons/category-placeholder.svg');
    }

    public function getPackagesProperty(): Collection
    {
        return Package::query()
            ->select(['id', 'category_id', 'name', 'description', 'image', 'order'])
            ->where('category_id', $this->category->id)
            ->where('is_active', true)
            ->withCount(['products' => fn ($query) => $query->where('is_active', true)])
            ->orderBy('order')
            ->orderBy('name')
            ->get()
            ->map(fn (Package $package): array => [
                'id' => $package->id,
                'name' => $package->name,
                'description' => (string) ($package->description ?? ''),
                'image' => filled($package->image) ? asset($package->image) : $this->placeholderImage,
                'products_count' => $package->products_count,
            ]);
    }

    public function render(): View
    {
        return $this->view()->title($this->category->name);
    }
};
?>

<div class="storefront-page storefront-page--browse mx-auto flex w-full flex-col gap-5" x-data="{ viewMode: 'grid' }" data-storefront-page="browse">
    <section class="storefront-card storefront-card--pad-none overflow-hidden">
        <div class="relative">
            <div class="sm:aspect-[16/4] w-full overflow-hidden bg-zinc-100 dark:bg-zinc-900">
                <img
                    src="{{ filled($category->image) ? asset($category->image) : $placeholderImage }}"
                    alt="{{ $category->name }}"
                    class="h-full w-full object-cover"
                    loading="lazy"
                    decoding="async"
                    onerror="this.onerror=null; this.src='{{ $placeholderImage }}';"
                />
            </div>
            <div class="pointer-events-none absolute inset-0 bg-gradient-to-t from-black/75 via-black/40 to-transparent"></div>

            <div class="absolute inset-x-0 top-0 p-3 sm:p-4">
                <nav class="flex flex-wrap items-center gap-2 text-[11px] font-medium text-white/90 sm:text-xs" aria-label="{{ __('main.breadcrumb_categories') }}">
                    <a href="{{ route('home') }}" wire:navigate class="rounded-full border border-white/35 bg-black/35 px-2.5 py-1 backdrop-blur-sm transition hover:border-white/55 hover:bg-black/45">
                        {{ __('main.breadcrumb_home') }}
                    </a>
                    <span class="text-white/50" aria-hidden="true">/</span>
                    <span class="rounded-full border border-white/35 bg-black/35 px-2.5 py-1 backdrop-blur-sm">
                        {{ __('main.breadcrumb_categories') }}
                    </span>
                    <span class="text-white/50" aria-hidden="true">/</span>
                    <span class="max-w-[12rem] truncate rounded-full border border-white/35 bg-black/35 px-2.5 py-1 backdrop-blur-sm sm:max-w-[18rem]">
                        {{ $category->name }}
                    </span>
                    @if ($this->packages->isNotEmpty())
                        <span
                            class="inline-flex items-center rounded-full border border-white/45 bg-white/10 px-2.5 py-1 text-[11px] font-semibold text-white shadow-sm backdrop-blur-sm sm:text-xs"
                            data-test="category-page-package-count-chip"
                        >
                            {{ __('main.category_packages_count', ['count' => $this->packages->count()]) }}
                        </span>
                    @endif
                </nav>
            </div>

            <div class="absolute inset-x-0 bottom-0 p-4 sm:p-6">
                <div class="flex flex-wrap items-end justify-between gap-3">
                    <div class="space-y-1">
                        <flux:text class="text-xs font-semibold tracking-[0.2em] text-white/80 uppercase">
                            {{ __('messages.categories') }}
                        </flux:text>
                        <flux:heading size="xl" class="text-white [text-shadow:0_1px_3px_rgb(0_0_0/0.65)]">
                            {{ $category->name }}
                        </flux:heading>
                    </div>

                    <div class="inline-flex items-center gap-1 rounded-full border border-white/30 bg-white/10 p-1 backdrop-blur-sm">
                        <button
                            type="button"
                            class="rounded-full px-3 py-1 text-xs font-medium text-white/85 transition hover:text-white"
                            x-on:click="viewMode = 'grid'"
                            x-bind:class="viewMode === 'grid' ? 'bg-white/20 text-white' : ''"
                        >
                            {{ __('main.view_mode_grid') }}
                        </button>
                        <button
                            type="button"
                            class="rounded-full px-3 py-1 text-xs font-medium text-white/85 transition hover:text-white"
                            x-on:click="viewMode = 'list'"
                            x-bind:class="viewMode === 'list' ? 'bg-white/20 text-white' : ''"
                        >
                            {{ __('main.view_mode_list') }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="storefront-card storefront-card--pad-md">
        <div class="mb-4 flex items-center justify-between gap-3">
            <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.packages') }}
            </flux:heading>
            <a
                href="{{ route('home') }}"
                wire:navigate
                class="inline-flex items-center gap-2 rounded-full border border-zinc-200 bg-white px-3 py-1.5 text-xs font-medium text-zinc-600 transition hover:border-accent hover:text-zinc-900 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
            >
                <flux:icon icon="arrow-left" class="size-3 rtl:rotate-180" />
                {{ __('main.homepage') }}
            </a>
        </div>

        @if ($this->packages->isEmpty())
            <div class="rounded-xl border border-dashed border-zinc-200 p-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400">
                {{ __('messages.no_packages_yet') }}
            </div>
        @else
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4 sm:gap-4 lg:grid-cols-4" x-show="viewMode === 'grid'">
                @foreach ($this->packages as $package)
                    <button
                        type="button"
                        wire:key="category-grid-package-{{ $package['id'] }}"
                        x-on:click="$dispatch('open-package-overlay', { packageId: {{ $package['id'] }} })"
                        class="group flex cursor-pointer flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white text-start text-zinc-900 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-accent hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:border-accent"
                    >
                        <div class="aspect-[4/3] w-full overflow-hidden bg-zinc-100 dark:bg-zinc-900">
                            <img
                                src="{{ $package['image'] }}"
                                alt="{{ $package['name'] }}"
                                class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                                loading="lazy"
                                decoding="async"
                                onerror="this.onerror=null; this.src='{{ $placeholderImage }}';"
                            />
                        </div>
                        <div class="flex flex-1 flex-col gap-1 px-3 pb-3 pt-2">
                            <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $package['name'] }}
                            </div>
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $package['products_count'] }} {{ __('messages.products') }}
                            </div>
                        </div>
                    </button>
                @endforeach
            </div>

            <div class="space-y-2" x-show="viewMode === 'list'" x-cloak>
                @foreach ($this->packages as $package)
                    <button
                        type="button"
                        wire:key="category-list-package-{{ $package['id'] }}"
                        x-on:click="$dispatch('open-package-overlay', { packageId: {{ $package['id'] }} })"
                        class="group flex w-full cursor-pointer items-center gap-3 rounded-xl border border-zinc-200 bg-white p-3 text-start shadow-sm transition hover:border-accent hover:shadow-md dark:border-zinc-700 dark:bg-zinc-800 dark:hover:border-accent"
                    >
                        <div class="size-14 shrink-0 overflow-hidden rounded-lg bg-zinc-100 dark:bg-zinc-900">
                            <img
                                src="{{ $package['image'] }}"
                                alt="{{ $package['name'] }}"
                                class="h-full w-full object-cover"
                                loading="lazy"
                                decoding="async"
                                onerror="this.onerror=null; this.src='{{ $placeholderImage }}';"
                            />
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="truncate text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $package['name'] }}
                            </div>

                        </div>
                        <div class="shrink-0 text-xs text-zinc-500 dark:text-zinc-400">
                            {{ $package['products_count'] }} {{ __('messages.products') }}
                        </div>
                    </button>
                @endforeach
            </div>
        @endif
    </section>

</div>
