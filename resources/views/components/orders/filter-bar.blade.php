@props([
    /** @var array<string, string> $filters */
    'filters',
    'activeFilter',
])

<nav
    class="flex gap-2 overflow-x-auto pb-1"
    aria-label="{{ __('messages.orders_filters_label') }}"
    data-test="orders-filter-bar"
    data-section="orders-filter-bar"
>
    @foreach ($filters as $value => $label)
        @php
            $isActive = $activeFilter === $value;
            $eventName = str_replace('_', '-', $value);
        @endphp
        <button
            type="button"
            wire:click="setFilter('{{ $value }}')"
            @class([
                'shrink-0 rounded-full border px-4 py-2 text-sm font-semibold transition data-loading:pointer-events-none data-loading:opacity-60',
                'border-(--color-accent) bg-(--color-accent) text-(--color-accent-foreground)' => $isActive,
                'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-600 dark:hover:bg-zinc-800' => ! $isActive,
            ])
            aria-pressed="{{ $isActive ? 'true' : 'false' }}"
            data-test="orders-filter-{{ $eventName }}"
            data-event="orders-filter-{{ $eventName }}"
        >
            {{ $label }}
        </button>
    @endforeach
</nav>
