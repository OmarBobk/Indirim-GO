@props([
    'detail' => [],
])

@php
    $detail = is_array($detail) ? $detail : [];
    $isCredit = (bool) ($detail['amount']['is_credit'] ?? false);
@endphp

<section
    class="storefront-card storefront-card--pad-md transaction-detail-header"
    data-test="transaction-detail-header"
    aria-labelledby="transaction-detail-heading"
>
    <p class="storefront-type-eyebrow">{{ $detail['receipt']['brand'] ?? config('app.name') }}</p>
    <h1 id="transaction-detail-heading" class="storefront-type-title mt-1">
        {{ $detail['heading'] ?? __('messages.transaction_detail_title') }}
    </h1>
    <p class="mt-2 font-mono text-sm text-zinc-600 dark:text-zinc-300" dir="ltr">
        <span class="sr-only">{{ __('messages.transaction_reference') }}:</span>
        {{ $detail['public_reference'] ?? '' }}
    </p>
    <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-200">
        <span class="font-medium">{{ $detail['type_label'] ?? '' }}</span>
        <span aria-hidden="true"> · </span>
        <span>{{ $detail['direction_label'] ?? '' }}</span>
        @if ($isCredit)
            <flux:icon name="arrow-down-circle" variant="mini" class="ms-1 inline size-4 text-emerald-700 dark:text-emerald-400" aria-hidden="true" />
        @else
            <flux:icon name="arrow-up-circle" variant="mini" class="ms-1 inline size-4 text-red-700 dark:text-red-400" aria-hidden="true" />
        @endif
    </p>
</section>
