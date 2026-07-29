@props([
    'filter' => 'all',
    'search' => '',
    'hasActive' => false,
])

@php
    $chips = [
        'all' => __('messages.refunds_filter_all'),
        'under_review' => __('messages.refunds_filter_under_review'),
        'refunded' => __('messages.refunds_filter_refunded'),
        'needs_action' => __('messages.refunds_filter_needs_action'),
        'closed' => __('messages.refunds_filter_closed'),
    ];
@endphp

<section class="space-y-3" data-test="refunds-filters" aria-label="{{ __('messages.refunds_filters_label') }}">
    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('messages.refunds_filters_label') }}">
        @foreach ($chips as $key => $label)
            <button
                type="button"
                wire:click="setFilter('{{ $key }}')"
                @class([
                    'rounded-full border px-3 py-1.5 text-sm font-medium transition',
                    'border-(--color-accent) bg-(--color-accent) text-(--color-accent-foreground)' => $filter === $key,
                    'border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200' => $filter !== $key,
                ])
                @if ($filter === $key) aria-current="true" @endif
                data-test="refunds-filter-{{ $key }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-center">
        <flux:input
            wire:model.live.debounce.400ms="search"
            :label="__('messages.refunds_search_label')"
            :placeholder="__('messages.refunds_search_placeholder')"
            class="w-full"
            data-test="refunds-search"
        />
        @if ($hasActive)
            <flux:button type="button" variant="ghost" size="sm" wire:click="clearFilters" data-test="refunds-clear-filters">
                {{ __('messages.refunds_clear_filters') }}
            </flux:button>
        @endif
    </div>
</section>
