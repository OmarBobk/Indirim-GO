@props([
    'rows' => 4,
])

<div {{ $attributes->class(['storefront-skeleton-list']) }} data-test="storefront-skeleton" aria-hidden="true">
    @for ($i = 0; $i < (int) $rows; $i++)
        <div class="storefront-skeleton-row" wire:key="storefront-skeleton-{{ $i }}"></div>
    @endfor
</div>
