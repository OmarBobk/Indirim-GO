@props([
    /** @var list<array{id: int, name: string, image: string, products_count: int, times_ordered: int}> $items */
    'items' => [],
])

<section
    class="mx-auto w-full max-w-7xl scroll-mt-24 px-3 sm:px-0"
    data-section="customer-home-frequently-ordered"
    data-test="customer-home-frequently-ordered"
    aria-labelledby="customer-home-frequently-ordered-heading"
>
    <div class="space-y-2.5">
        <div class="min-w-0">
            <h2
                id="customer-home-frequently-ordered-heading"
                class="text-base font-semibold tracking-tight text-zinc-900 dark:text-zinc-50 sm:text-lg"
            >
                {{ __('main.home_frequently_ordered') }}
            </h2>
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 sm:text-sm">
                {{ __('main.home_frequently_ordered_hint') }}
            </p>
        </div>

        @if ($items === [])
            <div
                class="rounded-xl bg-zinc-50 px-3 py-4 dark:bg-zinc-800/60"
                data-test="customer-home-frequently-ordered-empty"
            >
                <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">
                    {{ __('main.home_frequently_ordered_empty_lead') }}
                </p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 sm:text-sm">
                    {{ __('main.home_frequently_ordered_empty_hint') }}
                </p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center rounded-lg bg-(--color-accent) px-3.5 py-2 text-sm font-semibold text-(--color-accent-foreground) transition hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40"
                        data-test="customer-home-freq-empty-search"
                        data-event="home-freq-empty-search"
                        x-data
                        x-on:click="
                            const el = document.getElementById('customer-home-package-search-input');
                            if (! el) { return; }
                            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                            el.focus({ preventScroll: true });
                        "
                    >
                        {{ __('main.home_frequently_ordered_empty_search') }}
                    </button>
                    <a
                        href="#customer-home-browse"
                        class="inline-flex min-h-10 items-center rounded-lg border border-zinc-200 bg-white px-3.5 py-2 text-sm font-medium text-zinc-700 transition hover:border-(--color-accent) dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200"
                        data-test="customer-home-freq-empty-categories"
                        data-event="home-freq-empty-categories"
                    >
                        {{ __('main.home_frequently_ordered_empty_categories') }}
                    </a>
                    <a
                        href="#homepage-section-of-packages"
                        class="inline-flex min-h-10 items-center rounded-lg border border-zinc-200 bg-white px-3.5 py-2 text-sm font-medium text-zinc-700 transition hover:border-(--color-accent) dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200"
                        data-test="customer-home-freq-empty-packages"
                        data-event="home-freq-empty-packages"
                    >
                        {{ __('main.home_frequently_ordered_empty_packages') }}
                    </a>
                </div>
            </div>
        @else
            <div class="relative" data-test="customer-home-frequently-ordered-rail">
                <div
                    class="pointer-events-none absolute inset-y-0 end-0 z-10 w-12 bg-gradient-to-l from-white from-40% to-transparent dark:from-zinc-900 max-sm:w-14"
                    aria-hidden="true"
                ></div>
                <div
                    role="region"
                    aria-roledescription="{{ __('main.home_horizontal_rail') }}"
                    aria-label="{{ __('main.home_frequently_ordered') }}"
                    class="-mx-1 flex gap-3 overflow-x-auto px-1 pb-1 pe-10 scrollbar-hide snap-x snap-proximity scroll-ps-1 scroll-pe-10 sm:pe-12 sm:scroll-pe-12"
                    data-test="customer-home-frequently-ordered-scroller"
                >
                    @foreach ($items as $item)
                        <button
                            type="button"
                            wire:key="home-freq-{{ $item['id'] }}"
                            x-data
                            x-on:click="$dispatch('open-package-overlay', { packageId: {{ $item['id'] }} })"
                            class="group flex w-36 shrink-0 snap-start cursor-pointer flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white text-start shadow-sm transition hover:border-(--color-accent) focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) active:border-(--color-accent) dark:border-zinc-700 dark:bg-zinc-800 sm:w-40"
                            aria-label="{{ __('main.home_open_package_aria', ['name' => $item['name']]) }}"
                            title="{{ $item['name'] }}"
                            data-test="customer-home-frequently-ordered-item"
                            data-event="home-frequently-ordered-item"
                            data-package-id="{{ $item['id'] }}"
                        >
                            <div class="aspect-[4/3] w-full overflow-hidden bg-zinc-100 dark:bg-zinc-900">
                                <img
                                    src="{{ $item['image'] }}"
                                    alt=""
                                    class="h-full w-full object-cover"
                                    width="160"
                                    height="120"
                                    loading="lazy"
                                    decoding="async"
                                />
                            </div>
                            <div class="flex flex-1 flex-col gap-1 px-2.5 py-2">
                                <span class="line-clamp-2 text-xs font-semibold text-zinc-900 dark:text-zinc-100 sm:text-sm">
                                    {{ $item['name'] }}
                                </span>
                                <span class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ trans_choice('main.home_ordered_times', $item['times_ordered'], ['count' => $item['times_ordered']]) }}
                                </span>
                                <span
                                    class="mt-auto inline-flex items-center gap-0.5 text-xs font-semibold text-(--color-accent-content) dark:text-(--color-accent)"
                                    data-test="home-package-open-affordance"
                                    aria-hidden="true"
                                >
                                    {{ __('main.home_open') }}
                                    <flux:icon icon="chevron-right" class="size-3.5 rtl:rotate-180" />
                                </span>
                            </div>
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</section>
