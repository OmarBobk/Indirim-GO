@props([
    'filter' => 'all',
    'category' => '',
])

@php
    $primaryFilters = [
        'all' => __('messages.all'),
        'unread' => __('messages.unread'),
        'action_required' => __('messages.activity_filter_action_required'),
    ];

    $categoryFilters = [
        '' => __('messages.activity_filter_all_categories'),
        'orders' => __('messages.activity_category_orders'),
        'money' => __('messages.activity_category_money'),
        'rewards' => __('messages.activity_category_rewards'),
        'account' => __('messages.activity_category_account'),
    ];
@endphp

<div class="flex flex-col gap-3" data-test="activity-filters">
    <nav
        class="flex gap-2 overflow-x-auto pb-1"
        aria-label="{{ __('messages.activity_filters_label') }}"
        data-test="activity-filter-primary"
    >
        @foreach ($primaryFilters as $value => $label)
            @php $isActive = $filter === $value; @endphp
            <button
                type="button"
                wire:click="setFilter('{{ $value }}')"
                @class([
                    'shrink-0 rounded-full border px-4 py-2 text-sm font-semibold transition data-loading:pointer-events-none data-loading:opacity-60',
                    'border-(--color-accent) bg-(--color-accent) text-(--color-accent-foreground)' => $isActive,
                    'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200 dark:hover:border-zinc-600 dark:hover:bg-zinc-800' => ! $isActive,
                ])
                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                data-test="activity-filter-{{ $value }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <nav
        class="flex gap-2 overflow-x-auto pb-1"
        aria-label="{{ __('messages.activity_category_filters_label') }}"
        data-test="activity-filter-category"
    >
        @foreach ($categoryFilters as $value => $label)
            @php
                $isActive = $category === $value;
                $slug = $value === '' ? 'all-categories' : $value;
            @endphp
            <button
                type="button"
                wire:click="setCategory('{{ $value }}')"
                @class([
                    'shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium transition data-loading:pointer-events-none data-loading:opacity-60',
                    'border-(--color-accent) bg-(--color-accent)/10 text-(--color-accent)' => $isActive,
                    'border-zinc-200 bg-white text-zinc-600 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300 dark:hover:border-zinc-600 dark:hover:bg-zinc-800' => ! $isActive,
                ])
                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                data-test="activity-category-{{ $slug }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </nav>
</div>
