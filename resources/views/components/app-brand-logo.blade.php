@props([
    'href' => null,
])

@php
    use App\Support\BrandLogo;

    $href ??= route('home');
@endphp

<a
    href="{{ $href }}"
    {{ $attributes->class(['inline-flex shrink-0 items-center']) }}
    aria-label="{{ config('app.name') }}"
>
    <img
        src="{{ BrandLogo::lightUrl() }}"
        alt="{{ config('app.name') }}"
        class="h-9 w-auto sm:h-10 dark:hidden"
        width="160"
        height="40"
        decoding="async"
    >
    <img
        src="{{ BrandLogo::darkUrl() }}"
        alt="{{ config('app.name') }}"
        class="hidden h-9 w-auto sm:h-10 dark:block"
        width="160"
        height="40"
        decoding="async"
    >
</a>
