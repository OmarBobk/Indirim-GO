@props([
    /** @var array<string, mixed> $view */
    'view',
])

@php
    $needsAttention = $view['classification'] === \App\Support\CustomerOrderFulfillmentClassifier::NEEDS_ATTENTION;
@endphp

<div class="mx-auto w-full max-w-5xl px-3 py-6 sm:px-0 sm:py-10" data-section="order-detail-workspace">
    <div class="mb-4 flex items-center">
        <x-back-button :fallback="route('orders.index')" />
    </div>

    <div class="flex flex-col gap-5 sm:gap-6">
        <section
            class="rounded-2xl border border-zinc-200 bg-white px-4 py-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:px-5"
            data-section="order-detail-header"
            data-test="order-detail-header"
        >
            <x-orders.detail.header
                :order-number="$view['orderNumber']"
                :formatted-date="$view['formattedDate']"
            />
        </section>

        <x-orders.detail.attention-strip :visible="$needsAttention" />

        <section class="space-y-4" data-section="order-detail-units" data-test="order-detail-units">
            @foreach ($view['items'] as $item)
                <div
                    id="item-{{ $item['id'] }}-delivery"
                    class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-5"
                    wire:key="order-item-units-{{ $item['id'] }}"
                >
                    <x-orders.detail.units :units="$item['units']" />
                </div>
            @endforeach
        </section>

        <section class="space-y-3" data-section="order-detail-line-context" data-test="order-detail-line-context">
            @foreach ($view['items'] as $item)
                <div
                    id="item-{{ $item['id'] }}"
                    class="min-w-0 rounded-2xl border border-zinc-200/80 bg-white/90 p-4 dark:border-zinc-700/80 dark:bg-zinc-900/80 sm:p-5"
                    wire:key="order-item-context-{{ $item['id'] }}"
                >
                    <x-orders.detail.line-context
                        :item="$item"
                        :show-prices="$view['showPrices']"
                    />

                    <x-orders.detail.order-again
                        :show="$item['showOrderAgain'] ?? false"
                        :package-id="$item['orderAgainPackageId'] ?? null"
                        :product-id="$item['orderAgainProductId'] ?? null"
                        :label="$item['orderAgainLabel'] ?? null"
                    />
                </div>
            @endforeach
        </section>

        <section
            class="rounded-xl border border-zinc-200/70 bg-zinc-50/80 px-3 py-3 dark:border-zinc-800 dark:bg-zinc-900/50 sm:px-4"
            data-section="order-detail-summary"
            data-test="order-detail-summary"
        >
            <x-orders.detail.summary
                :order-id="$view['orderId']"
                :payment-status="$view['paymentStatus']"
                :show-prices="$view['showPrices']"
                :formatted-total="$view['formattedTotal']"
                :created-label="$view['createdLabel']"
            />
        </section>
    </div>
</div>
