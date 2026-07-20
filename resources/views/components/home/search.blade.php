@props([])

<section
    class="mx-auto w-full max-w-7xl px-3 sm:px-0"
    data-section="customer-home-search"
    data-test="customer-home-search"
    aria-label="{{ __('main.search_packages_placeholder') }}"
>
    <div class="rounded-2xl border border-zinc-200 bg-white p-3 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-4">
        <x-storefront.package-search input-id="customer-home-package-search-input" />
    </div>
</section>
