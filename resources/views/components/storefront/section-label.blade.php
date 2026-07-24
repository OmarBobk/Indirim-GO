@props([
    'as' => 'h2',
])

<{{ $as }} {{ $attributes->class(['storefront-type-eyebrow']) }}>
    {{ $slot }}
</{{ $as }}>
