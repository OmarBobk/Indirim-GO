@props([
    'filters' => [],
    'a11y' => [],
])

@php
    $filters = is_array($filters) ? $filters : [];
    $a11y = is_array($a11y) ? $a11y : [];
    $status = $filters['status'] ?? 'all';
@endphp

<section class="storefront-section-stack" data-test="earnings-filters" aria-label="{{ $a11y['filters'] ?? __('messages.earnings_filters_label') }}">
    <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('messages.earnings_status_filters') }}">
        @foreach ([
            'all' => __('messages.earnings_filter_all'),
            'pending' => __('messages.earnings_filter_pending'),
            'credited' => __('messages.earnings_filter_credited'),
            'failed' => __('messages.earnings_filter_failed'),
        ] as $key => $label)
            <button
                type="button"
                wire:click="setStatus('{{ $key }}')"
                @class([
                    'rounded-full border px-3 py-1.5 text-sm font-medium transition',
                    'border-(--color-accent) bg-(--color-accent) text-(--color-accent-foreground)' => $status === $key,
                    'border-zinc-200 bg-white text-zinc-700 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200' => $status !== $key,
                ])
                @if ($status === $key) aria-pressed="true" @else aria-pressed="false" @endif
                data-test="earnings-filter-{{ $key }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </div>

    <div class="flex flex-col gap-2 sm:flex-row sm:items-end">
        <div class="min-w-0 flex-1">
            <label for="earnings-search" class="sr-only">{{ $a11y['search'] ?? __('messages.earnings_search_label') }}</label>
            <flux:input
                id="earnings-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                :placeholder="__('messages.earnings_search_placeholder')"
                data-test="earnings-search"
            />
        </div>
        @if (! empty($filters['has_active']))
            <flux:button type="button" variant="ghost" wire:click="clearFilters" data-test="earnings-clear-filters">
                {{ __('messages.financial_ledger_clear_filters') }}
            </flux:button>
        @endif
    </div>
</section>
