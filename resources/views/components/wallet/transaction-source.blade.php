@props([
    'detail' => [],
])

@php
    $detail = is_array($detail) ? $detail : [];
    $source = is_array($detail['source'] ?? null) ? $detail['source'] : [];
@endphp

<section
    class="storefront-card storefront-card--pad-md"
    data-test="transaction-source"
    aria-labelledby="transaction-source-heading"
>
    <h2 id="transaction-source-heading" class="storefront-type-section">
        {{ $source['heading'] ?? __('messages.transaction_source_heading') }}
    </h2>

    @if (! empty($source['unavailable']))
        <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300" data-test="transaction-source-unavailable">
            {{ $source['unavailable_label'] ?? __('messages.transaction_related_unavailable') }}
        </p>
    @else
        <dl class="mt-4 grid gap-3">
            @if (! empty($source['title']))
                <div>
                    <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_related_source') }}</dt>
                    <dd class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100">{{ $source['title'] }}</dd>
                </div>
            @endif
            @if (! empty($source['order_number']))
                <div>
                    <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_related_order') }}</dt>
                    <dd class="mt-0.5 font-mono text-sm text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $source['order_number'] }}</dd>
                </div>
            @endif
            @if (! empty($source['topup_public_ref']))
                <div>
                    <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_related_topup') }}</dt>
                    <dd class="mt-0.5 font-mono text-sm text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $source['topup_public_ref'] }}</dd>
                </div>
            @endif
            @if (! empty($source['refund_public_ref']))
                <div>
                    <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_related_refund') }}</dt>
                    <dd class="mt-0.5 font-mono text-sm text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $source['refund_public_ref'] }}</dd>
                </div>
            @endif
            @if (! empty($source['payment_method']))
                <div>
                    <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_payment_method') }}</dt>
                    <dd class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100">{{ $source['payment_method'] }}</dd>
                </div>
            @endif
            @if (! empty($source['product_label']))
                <div>
                    <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_product_summary') }}</dt>
                    <dd class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100">{{ $source['product_label'] }}</dd>
                </div>
            @endif
            @if (! empty($source['customer_reason']))
                <div>
                    <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_adjustment_reason') }}</dt>
                    <dd class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100">{{ $source['customer_reason'] }}</dd>
                </div>
            @endif
            @if (! empty($source['description']))
                <div>
                    <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.details') }}</dt>
                    <dd class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100">{{ $source['description'] }}</dd>
                </div>
            @endif
        </dl>

        @if (! empty($source['href']) && ! empty($source['destination_label']))
            <p class="mt-4 transaction-detail-no-print">
                <a
                    href="{{ $source['href'] }}"
                    wire:navigate
                    class="text-sm font-medium text-(--color-accent) hover:underline"
                    data-test="transaction-source-link"
                >
                    {{ $source['destination_label'] }}
                </a>
            </p>
        @endif
    @endif
</section>
