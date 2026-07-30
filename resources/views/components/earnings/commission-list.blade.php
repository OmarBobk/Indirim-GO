@props([
    'items' => [],
    'isEmpty' => false,
    'isFilteredEmpty' => false,
    'search' => '',
])

@php
    $items = is_array($items) ? $items : [];
@endphp

<section data-test="earnings-commission-list" aria-labelledby="earnings-list-heading">
    <h2 id="earnings-list-heading" class="storefront-type-section">{{ __('messages.earnings_commissions_heading') }}</h2>

    @if ($isEmpty)
        <x-storefront.empty
            class="mt-4"
            data-test="earnings-empty"
            :title="__('messages.earnings_empty_title')"
            :description="__('messages.earnings_empty_hint')"
        />
    @elseif ($isFilteredEmpty)
        <x-storefront.empty
            class="mt-4"
            data-test="earnings-filtered-empty"
            :title="__('messages.earnings_filtered_empty_title')"
            :description="__('messages.earnings_filtered_empty_hint', ['query' => $search])"
        />
    @else
        <ul class="mt-4 divide-y divide-zinc-100 dark:divide-zinc-800" role="list">
            @foreach ($items as $item)
                <x-earnings.commission-row :item="$item" wire:key="{{ $item['stable_key'] ?? $loop->index }}" />
            @endforeach
        </ul>
    @endif
</section>
