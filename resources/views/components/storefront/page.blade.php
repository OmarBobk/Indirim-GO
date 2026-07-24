@props([
    /**
     * Width tier from the storefront experience contract.
     * browse = catalog density (7xl)
     * work = ops / account (4xl)
     * work-wide = order detail (5xl → 6xl)
     * focus = dense forms (2xl)
     */
    'width' => 'work',
])

@php
    $widthClass = match ($width) {
        'browse' => 'storefront-page--browse',
        'focus' => 'storefront-page--focus',
        'work-wide' => 'storefront-page--work-wide',
        default => 'storefront-page--work',
    };
@endphp

<div {{ $attributes->class(['storefront-page', $widthClass]) }} data-storefront-page="{{ $width }}">
    {{ $slot }}
</div>
