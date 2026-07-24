@props([
    'interactive' => false,
    'padding' => 'md',
])

@php
    $paddingClass = match ($padding) {
        'none' => 'storefront-card--pad-none',
        'sm' => 'storefront-card--pad-sm',
        'lg' => 'storefront-card--pad-lg',
        default => 'storefront-card--pad-md',
    };
@endphp

<div
    {{ $attributes->class([
        'storefront-card',
        $paddingClass,
        'storefront-card--interactive' => $interactive,
    ]) }}
>
    {{ $slot }}
</div>
