@props([
    'context' => 'generic',
])

@php
    $title = match ($context) {
        'cart' => __('messages.prices_gated_cart_title'),
        'search' => __('messages.prices_gated_search_title'),
        'wallet' => __('messages.prices_gated_wallet_title'),
        'buy_now' => __('messages.prices_gated_buy_now_title'),
        default => __('messages.prices_gated_title'),
    };
    $body = __('messages.prices_gated_body');
    $contactLabel = __('messages.prices_gated_contact_cta');
@endphp

<div
    {{ $attributes->class([
        'rounded-xl border border-amber-200 bg-amber-50/90 px-3 py-3 dark:border-amber-800/60 dark:bg-amber-950/30',
    ]) }}
    role="status"
    data-test="prices-gated"
    data-prices-gated-context="{{ $context }}"
>
    <p class="text-sm font-semibold text-amber-950 dark:text-amber-100">
        {{ $title }}
    </p>
    <p class="mt-1 text-xs text-amber-900/90 dark:text-amber-200/90">
        {{ $body }}
    </p>
    <a
        href="{{ route('contact') }}"
        wire:navigate
        class="mt-2 inline-flex min-h-9 items-center text-xs font-semibold text-(--color-accent) underline-offset-2 hover:underline"
        data-test="prices-gated-contact"
    >
        {{ $contactLabel }}
    </a>
</div>
