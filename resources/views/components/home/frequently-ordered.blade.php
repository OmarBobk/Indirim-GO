@props([])

@php
    $items = auth()->check()
        ? \App\Support\CustomerHomeFrequentlyOrdered::forUser(auth()->user())
        : [];
@endphp

<section
    class="mx-auto w-full max-w-7xl px-3 sm:px-0"
    data-section="customer-home-frequently-ordered"
    data-test="customer-home-frequently-ordered"
    aria-labelledby="customer-home-frequently-ordered-heading"
>
    <div class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5">
        <div class="mb-3 flex items-end justify-between gap-3">
            <div class="min-w-0">
                <h2
                    id="customer-home-frequently-ordered-heading"
                    class="text-base font-semibold text-zinc-900 dark:text-zinc-100"
                >
                    {{ __('main.home_frequently_ordered') }}
                </h2>
                <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                    {{ __('main.home_frequently_ordered_hint') }}
                </p>
            </div>
        </div>

        @if ($items === [])
            <div
                class="rounded-xl border border-dashed border-zinc-200 px-3 py-6 text-center text-sm text-zinc-500 dark:border-zinc-700 dark:text-zinc-400"
                data-test="customer-home-frequently-ordered-empty"
            >
                <p>{{ __('main.home_frequently_ordered_empty') }}</p>
                <p class="mt-1 text-xs">{{ __('main.home_frequently_ordered_empty_hint') }}</p>
            </div>
        @else
            <div
                class="-mx-1 flex gap-3 overflow-x-auto px-1 pb-1"
                data-test="customer-home-frequently-ordered-scroller"
            >
                @foreach ($items as $item)
                    <button
                        type="button"
                        wire:key="home-freq-{{ $item['id'] }}"
                        x-data
                        x-on:click="$dispatch('open-package-overlay', { packageId: {{ $item['id'] }} })"
                        class="group flex w-36 shrink-0 cursor-pointer flex-col overflow-hidden rounded-xl border border-zinc-200 bg-white text-start shadow-sm transition hover:border-(--color-accent) focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent) dark:border-zinc-700 dark:bg-zinc-800 sm:w-40"
                        aria-label="{{ $item['name'] }}"
                        data-test="customer-home-frequently-ordered-item"
                        data-event="home-frequently-ordered-item"
                        data-package-id="{{ $item['id'] }}"
                    >
                        <div class="aspect-[4/3] w-full overflow-hidden bg-zinc-100 dark:bg-zinc-900">
                            <img
                                src="{{ $item['image'] }}"
                                alt="{{ $item['name'] }}"
                                class="h-full w-full object-cover"
                                width="160"
                                height="120"
                                loading="lazy"
                                decoding="async"
                            />
                        </div>
                        <div class="flex flex-1 flex-col gap-0.5 px-2.5 py-2">
                            <span class="line-clamp-2 text-xs font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $item['name'] }}
                            </span>
                            <span class="text-[10px] text-zinc-500 dark:text-zinc-400">
                                {{ trans_choice('main.home_ordered_times', $item['times_ordered'], ['count' => $item['times_ordered']]) }}
                            </span>
                        </div>
                    </button>
                @endforeach
            </div>
        @endif
    </div>
</section>
