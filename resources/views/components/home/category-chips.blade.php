@props([
    /** @var list<array{id: int, name: string, slug: string, image: string}> $categories */
    'categories' => [],
])

@php
    $scrollToSearch = "
        const el = document.getElementById('customer-home-package-search-input');
        if (! el) { return; }
        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        el.scrollIntoView({ behavior: reduceMotion ? 'auto' : 'smooth', block: 'center' });
        el.focus({ preventScroll: true });
    ";
@endphp

<section
    class="mx-auto w-full max-w-7xl px-3 sm:px-0"
    data-section="customer-home-category-chips"
    data-test="customer-home-category-chips"
    aria-labelledby="customer-home-categories-heading"
    aria-describedby="customer-home-categories-hint"
>
    <div class="space-y-2.5 sm:space-y-3">
        <div class="min-w-0">
            <h2
                id="customer-home-categories-heading"
                class="text-base font-semibold tracking-tight text-pretty text-zinc-900 dark:text-zinc-50 sm:text-lg"
            >
                {{ __('messages.categories') }}
            </h2>
            <p
                id="customer-home-categories-hint"
                class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400 sm:text-sm"
            >
                {{ __('main.home_browse_hint') }}
            </p>
        </div>

        @if ($categories === [])
            <div
                class="rounded-xl bg-zinc-50 px-3 py-4 dark:bg-zinc-800/60"
                data-test="customer-home-categories-empty"
            >
                <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">
                    {{ __('main.home_browse_empty_lead') }}
                </p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400 sm:text-sm">
                    {{ __('main.home_browse_empty_hint') }}
                </p>
                <div class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-2">
                    <button
                        type="button"
                        class="inline-flex min-h-10 items-center rounded-lg bg-(--color-accent) px-3.5 py-2 text-sm font-semibold text-(--color-accent-foreground) transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40"
                        data-test="customer-home-browse-empty-search"
                        data-event="home-browse-empty-search"
                        x-data
                        x-on:click="{!! $scrollToSearch !!}"
                    >
                        {{ __('main.home_frequently_ordered_empty_search') }}
                    </button>
                    <a
                        href="#homepage-section-of-packages"
                        class="inline-flex min-h-10 items-center rounded-lg border border-zinc-200 bg-white px-3.5 py-2 text-sm font-medium text-zinc-700 transition-colors hover:border-(--color-accent) focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200"
                        data-test="customer-home-browse-empty-packages"
                        data-event="home-browse-empty-packages"
                    >
                        {{ __('main.home_frequently_ordered_empty_packages') }}
                    </a>
                </div>
            </div>
        @else
            {{-- Soft tray invites tap; wrap on large screens so nothing is hidden. --}}
            <div
                class="rounded-2xl bg-zinc-50/90 p-2.5 sm:p-3 dark:bg-zinc-800/50"
                data-test="customer-home-category-rail"
            >
                <div class="relative">
                    <div
                        class="pointer-events-none absolute inset-y-0 end-0 z-10 w-14 bg-gradient-to-l from-zinc-50 from-30% to-transparent dark:from-zinc-800 max-lg:block lg:hidden sm:w-16"
                        aria-hidden="true"
                    ></div>
                    <div
                        role="navigation"
                        aria-label="{{ __('messages.categories') }}"
                        class="-mx-0.5 flex gap-2 overflow-x-auto px-0.5 pb-0.5 pe-12 scrollbar-hide snap-x snap-proximity scroll-ps-0.5 scroll-pe-12 sm:gap-2.5 sm:pe-14 sm:scroll-pe-14 lg:flex-wrap lg:gap-3 lg:overflow-visible lg:pe-0 lg:snap-none"
                        data-test="customer-home-category-scroller"
                    >
                        @foreach ($categories as $category)
                            <a
                                href="{{ route('categories.show', ['category' => $category['slug']]) }}"
                                wire:navigate
                                wire:key="home-cat-chip-{{ $category['id'] }}"
                                class="inline-flex min-h-11 shrink-0 snap-start items-center gap-2.5 rounded-full border border-zinc-200/90 bg-white py-2 ps-2 pe-3.5 text-sm font-medium text-zinc-800 shadow-sm transition-[border-color,box-shadow,transform] hover:border-(--color-accent) hover:shadow focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) active:scale-[0.98] active:border-(--color-accent) motion-reduce:transition-none motion-reduce:active:scale-100 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-100"
                                data-test="customer-home-category-chip"
                                data-event="home-category-chip"
                                title="{{ $category['name'] }}"
                            >
                                <img
                                    src="{{ $category['image'] }}"
                                    alt=""
                                    class="size-8 rounded-full object-cover"
                                    width="32"
                                    height="32"
                                    loading="lazy"
                                    decoding="async"
                                />
                                <span class="max-w-[10rem] truncate">{{ $category['name'] }}</span>
                            </a>
                        @endforeach
                    </div>
                </div>
            </div>
        @endif
    </div>
</section>
