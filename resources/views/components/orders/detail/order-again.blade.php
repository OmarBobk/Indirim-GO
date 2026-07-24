@props([
    'show' => false,
    'packageId' => null,
    'productId' => null,
    'label' => null,
])

@if ($show && $productId !== null)
    <div class="mt-4" data-section="order-detail-order-again" data-test="order-detail-order-again">
        <flux:button
            type="button"
            variant="primary"
            class="w-full sm:w-auto"
            x-on:click="$dispatch('open-buy-now', { productId: {{ (int) $productId }}, quantity: 1 })"
            data-test="order-detail-order-again-button"
            data-order-again-package-id="{{ $packageId !== null ? (int) $packageId : '' }}"
            data-order-again-product-id="{{ (int) $productId }}"
        >
            {{ $label ?? __('messages.order_again') }}
        </flux:button>
    </div>
@endif
