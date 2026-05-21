@php
    $pricesVisible = \App\Models\WebsiteSetting::getPricesVisible();
@endphp
<div
    class="relative w-full"
    x-data="storefrontPackageSearch({
        searchUrl: @js(route('api.storefront.packages.search')),
        minLength: 2,
        pricesVisible: @js($pricesVisible),
        strings: {
            noResults: @js(__('main.package_search_no_results')),
            typeMore: @js(__('main.package_search_type_more')),
            loading: @js(__('main.package_search_loading')),
            productsLabel: @js(__('messages.products')),
        },
    })"
    x-on:keydown.escape.window="closePanel()"
    x-on:click.outside="closePanel()"
>
    <div class="relative">
        <flux:icon
            icon="magnifying-glass"
            class="pointer-events-none absolute start-3 top-1/2 z-10 size-5 -translate-y-1/2 text-zinc-400"
        />
        <input
            type="search"
            x-model="query"
            x-on:focus="onFocus()"
            x-on:input.debounce.300ms="search()"
            autocomplete="off"
            autocorrect="off"
            spellcheck="false"
            placeholder="{{ __('main.search_packages_placeholder') }}"
            class="w-full rounded-lg border border-zinc-200 bg-white py-2.5 ps-10 pe-10 text-sm text-zinc-900 shadow-sm transition focus:border-(--color-accent) focus:outline-none focus:ring-0 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100"
            aria-label="{{ __('main.search_packages_placeholder') }}"
            aria-expanded="false"
            x-bind:aria-expanded="panelOpen ? 'true' : 'false'"
            aria-controls="storefront-package-search-results"
            role="combobox"
            aria-autocomplete="list"
        />
        <button
            type="button"
            x-show="query.length > 0"
            x-on:click="clear()"
            x-cloak
            class="absolute end-2 top-1/2 z-10 flex size-8 -translate-y-1/2 cursor-pointer items-center justify-center rounded-full text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 dark:hover:bg-zinc-700 dark:hover:text-zinc-200"
            aria-label="{{ __('main.package_search_clear') }}"
        >
            <flux:icon icon="x-mark" class="size-5" />
        </button>
    </div>

    <div
        x-show="panelOpen"
        x-cloak
        x-transition:enter="transition ease-out duration-150"
        x-transition:enter-start="opacity-0 translate-y-1"
        x-transition:enter-end="opacity-100 translate-y-0"
        class="absolute start-0 end-0 top-full z-[60] mt-2"
    >
        <div
            id="storefront-package-search-results"
            class="max-h-[min(70vh,28rem)] overflow-y-auto rounded-2xl border border-zinc-200 bg-white p-3 shadow-xl dark:border-zinc-700 dark:bg-zinc-900"
            role="listbox"
        >
            <template x-if="loading">
                <p class="px-2 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400" x-text="strings.loading"></p>
            </template>

            <template x-if="!loading && hint">
                <p class="px-2 py-6 text-center text-sm text-zinc-500 dark:text-zinc-400" x-text="hint"></p>
            </template>

            <template x-if="!loading && !hint && results.length > 0">
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 sm:gap-3">
                    <template x-for="pkg in results" :key="pkg.id">
                        <button
                            type="button"
                            role="option"
                            class="group flex cursor-pointer flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white text-start text-zinc-900 shadow-sm transition duration-200 hover:-translate-y-0.5 hover:border-accent hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100"
                            x-on:click="selectPackage(pkg)"
                        >
                            <div class="aspect-[4/3] w-full overflow-hidden bg-zinc-100 dark:bg-zinc-900">
                                <img
                                    :src="pkg.image"
                                    :alt="pkg.name"
                                    class="h-full w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                                    width="320"
                                    height="240"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>
                            <div class="flex flex-1 flex-col gap-0.5 px-2.5 pb-2.5 pt-2">
                                <div class="line-clamp-2 text-sm font-semibold text-zinc-900 dark:text-zinc-100" x-text="pkg.name"></div>
                                <div
                                    class="text-xs text-zinc-500 dark:text-zinc-400"
                                    x-show="!pricesVisible"
                                    x-text="pkg.products_count + ' ' + strings.productsLabel"
                                ></div>
                                <div
                                    x-show="pricesVisible && pkg.from_price_label"
                                    class="text-base font-bold text-(--color-accent)"
                                    dir="ltr"
                                    x-text="pkg.from_price_label"
                                ></div>
                            </div>
                        </button>
                    </template>
                </div>
            </template>
        </div>
    </div>
</div>
