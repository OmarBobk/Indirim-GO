@props([
    /** @var list<array{id: int, name: string, image: string, products_count: int}> $packages */
    'packages' => [],
])

{{-- Catalog Zone: popular / featured packages — store shelf. --}}
<section
    id="homepage-section-of-packages"
    class="mx-auto w-full max-w-7xl scroll-mt-24 px-3 sm:px-0"
    data-section="customer-home-popular-packages"
    data-test="customer-home-catalog"
    data-zone="catalog"
    aria-labelledby="customer-home-packages-heading"
>
    <div class="space-y-2.5 sm:space-y-3">
        <div class="min-w-0 border-t border-zinc-200/80 pt-3 dark:border-zinc-700/80 sm:pt-3.5">
            <h2
                id="customer-home-packages-heading"
                class="text-base font-semibold tracking-tight text-pretty text-zinc-900 dark:text-zinc-50 sm:text-lg"
            >
                {{ __('main.home_popular_packages') }}
            </h2>
            <p
                id="customer-home-packages-hint"
                class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 sm:text-sm"
            >
                {{ __('main.home_catalog_shelf_hint') }}
            </p>
        </div>

        <div
            data-test="customer-home-popular-packages"
            aria-describedby="customer-home-packages-hint"
        >
            <x-home.package-shelf :packages="$packages" />
        </div>
    </div>
</section>
