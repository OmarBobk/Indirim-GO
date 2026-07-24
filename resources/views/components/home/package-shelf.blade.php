@props([
    /** @var list<array{id: int, name: string, image: string, products_count: int}> $packages */
    'packages' => [],
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

@if ($packages === [])
    <div
        class="rounded-xl bg-zinc-50 px-3 py-4 dark:bg-zinc-800/60"
        data-test="section-of-packages-empty"
    >
        <p class="text-sm font-medium text-zinc-800 dark:text-zinc-100">
            {{ __('main.home_catalog_empty_lead') }}
        </p>
        <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
            {{ __('main.home_catalog_empty_hint') }}
        </p>
        <div class="mt-3 flex flex-wrap gap-2">
            <button
                type="button"
                class="inline-flex min-h-10 items-center rounded-lg bg-(--color-accent) px-3.5 py-2 text-sm font-semibold text-(--color-accent-foreground) transition-opacity hover:opacity-90 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40"
                data-test="home-catalog-empty-search"
                data-event="home-catalog-empty-search"
                x-data
                x-on:click="{!! $scrollToSearch !!}"
            >
                {{ __('main.home_frequently_ordered_empty_search') }}
            </button>
            <a
                href="#customer-home-browse"
                class="inline-flex min-h-10 items-center rounded-lg border border-zinc-200 bg-white px-3.5 py-2 text-sm font-medium text-zinc-700 transition-colors hover:border-(--color-accent) focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-(--color-accent)/40 dark:border-zinc-600 dark:bg-zinc-900 dark:text-zinc-200"
                data-test="home-catalog-empty-categories"
                data-event="home-catalog-empty-categories"
            >
                {{ __('main.home_frequently_ordered_empty_categories') }}
            </a>
        </div>
    </div>
@else
    <div
        class="grid grid-cols-2 gap-x-2.5 gap-y-3 sm:grid-cols-3 sm:gap-x-3 sm:gap-y-4 lg:grid-cols-4 lg:gap-x-4 lg:gap-y-5"
        data-test="customer-home-package-shelf-grid"
        x-data
    >
        @foreach ($packages as $package)
            <a
                href="{{ route('home', ['package' => $package['id']]) }}"
                wire:key="home-catalog-pkg-{{ $package['id'] }}"
                x-on:click="
                    if ($event.metaKey || $event.ctrlKey || $event.shiftKey || $event.altKey || $event.button !== 0) { return; }
                    $event.preventDefault();
                    $dispatch('open-package-overlay', { packageId: {{ $package['id'] }} });
                "
                class="group flex cursor-pointer flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white text-start text-zinc-900 shadow-sm transition-[transform,box-shadow,border-color] duration-200 hover:-translate-y-0.5 hover:border-accent hover:shadow-md focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) active:border-accent motion-reduce:transition-none motion-reduce:hover:translate-y-0 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:border-accent"
                aria-label="{{ __('main.home_open_package_aria', ['name' => $package['name']]) }}"
                title="{{ $package['name'] }}"
                data-test="customer-home-catalog-package"
            >
                <div class="aspect-[4/3] w-full overflow-hidden bg-zinc-100 dark:bg-zinc-900">
                    <img
                        src="{{ $package['image'] }}"
                        alt=""
                        class="h-full w-full object-cover transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                        width="320"
                        height="240"
                        loading="lazy"
                        decoding="async"
                    />
                </div>
                <div class="flex flex-1 flex-col gap-1 px-3 pb-3 pt-2">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $package['name'] }}
                    </div>
                    <div class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                        {{ $package['products_count'] }} {{ __('messages.products') }}
                    </div>
                    <span
                        class="mt-1 inline-flex items-center gap-0.5 text-xs font-semibold text-(--color-accent-content) dark:text-(--color-accent)"
                        data-test="home-package-open-affordance"
                        aria-hidden="true"
                    >
                        {{ __('main.home_open') }}
                        <flux:icon icon="chevron-right" class="size-3.5 rtl:rotate-180" />
                    </span>
                </div>
            </a>
        @endforeach
    </div>
@endif
