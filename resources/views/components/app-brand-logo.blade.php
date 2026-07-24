@props([
    'href' => null,
    'placement' => 'header',
])

@php
    use App\Support\BrandLogo;

    $href ??= route('home');
    $imageClasses = BrandLogo::imageClasses($placement);
@endphp

<a
    href="{{ $href }}"
    {{ $attributes->class(['inline-flex shrink-0 items-center']) }}
    aria-label="{{ config('app.name') }}"
>
    <img
        src="{{ BrandLogo::lightUrl() }}"
        alt="{{ config('app.name') }}"
        class="{{ $imageClasses }} object-contain object-left dark:hidden"
        decoding="async"
    >
    <img
        src="{{ BrandLogo::darkUrl() }}"
        alt="{{ config('app.name') }}"
        class="{{ $imageClasses }} hidden object-contain object-left dark:block"
        decoding="async"
    >
</a>
