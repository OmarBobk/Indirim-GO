<section
    class="mx-auto w-full max-w-7xl scroll-mt-[calc(var(--storefront-top-bar-height,3.25rem)+0.5rem)] px-3 pt-0.5 sm:px-0 sm:pt-0"
    data-section="customer-home-search"
    data-test="customer-home-search"
    aria-label="{{ __('main.home_search_region') }}"
>
    <div class="w-full">
        <x-storefront.package-search
            input-id="customer-home-package-search-input"
            :placeholder="__('main.home_search_placeholder')"
            size="hero"
        />
    </div>
</section>
