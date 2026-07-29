@props(['detail' => []])

@php $detail = is_array($detail) ? $detail : []; @endphp

<section class="storefront-card storefront-card--pad-md" data-test="refund-order-context" aria-labelledby="refund-order-context-heading">
    <flux:heading size="sm" id="refund-order-context-heading">{{ __('messages.refund_order_context_heading') }}</flux:heading>
    <dl class="mt-3 space-y-2 text-sm">
        @if (! empty($detail['order_number']))
            <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('messages.refund_order_reference') }}</dt>
                <dd class="font-medium text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $detail['order_number'] }}</dd>
            </div>
        @endif
        <div class="flex flex-wrap justify-between gap-2">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('messages.refund_product_label') }}</dt>
            <dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ $detail['product_label'] ?? '' }}</dd>
        </div>
        @if (! empty($detail['quantity_label']))
            <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('messages.refund_quantity_label') }}</dt>
                <dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ $detail['quantity_label'] }}</dd>
            </div>
        @endif
        @if (! empty($detail['fulfillment_status_label']))
            <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('messages.refund_fulfillment_label') }}</dt>
                <dd class="font-medium text-zinc-900 dark:text-zinc-100">{{ $detail['fulfillment_status_label'] }}</dd>
            </div>
        @endif
    </dl>
    @if (! empty($detail['order_href']))
        <a href="{{ $detail['order_href'] }}" wire:navigate class="mt-4 inline-block text-sm font-medium text-(--color-accent) hover:underline" data-test="refund-view-order">
            {{ __('messages.refund_view_order') }}
        </a>
    @endif
</section>
