@props([
    /** @var array<string, mixed> $view */
    'view',
])

@php
    $needsAttention = $view['classification'] === \App\Support\CustomerOrderFulfillmentClassifier::NEEDS_ATTENTION;
@endphp

<div class="mx-auto w-full storefront-page storefront-page--work-wide" data-section="order-detail-workspace" data-storefront-page="work-wide">
    <x-storefront.page-header
        :show-back="true"
        :back-fallback="route('orders.index')"
        class="!mb-4"
    />

    <div class="flex flex-col gap-5 sm:gap-6">
        <x-storefront.card padding="sm" data-section="order-detail-header" data-test="order-detail-header">
            <x-orders.detail.header
                :order-number="$view['orderNumber']"
                :formatted-date="$view['formattedDate']"
            />
        </x-storefront.card>

        <x-orders.detail.attention-strip :visible="$needsAttention" />

        {{--
            Single component tree. Mobile source order: units → line → order-again → summary.
            Large screens: main column (units + line) | sidebar (summary above order-again).
        --}}
        <div
            class="flex flex-col gap-5 sm:gap-6 lg:grid lg:grid-cols-[minmax(0,1fr)_minmax(16rem,20rem)] lg:grid-rows-[auto_auto] lg:items-start lg:gap-x-6 lg:gap-y-4"
            data-section="order-detail-desktop-layout"
            data-test="order-detail-desktop-layout"
        >
            <section
                class="min-w-0 space-y-4 lg:col-start-1 lg:row-start-1"
                data-section="order-detail-units"
                data-test="order-detail-units"
            >
                @foreach ($view['items'] as $item)
                    <div
                        id="item-{{ $item['id'] }}-delivery"
                        class="storefront-card storefront-card--pad-md min-w-0"
                        wire:key="order-item-units-{{ $item['id'] }}"
                    >
                        <x-orders.detail.units :units="$item['units']" />
                    </div>
                @endforeach
            </section>

            <section
                class="min-w-0 space-y-3 lg:col-start-1 lg:row-start-2"
                data-section="order-detail-line-context"
                data-test="order-detail-line-context"
            >
                @foreach ($view['items'] as $item)
                    <div
                        id="item-{{ $item['id'] }}"
                        class="storefront-card storefront-card--pad-md min-w-0 opacity-95"
                        wire:key="order-item-context-{{ $item['id'] }}"
                    >
                        <x-orders.detail.line-context
                            :item="$item"
                            :show-prices="$view['showPrices']"
                        />
                    </div>
                @endforeach
            </section>

            <section
                class="min-w-0 space-y-3 lg:col-start-2 lg:row-start-2"
                data-section="order-detail-order-again-panel"
                data-test="order-detail-order-again-panel"
            >
                @foreach ($view['items'] as $item)
                    <div wire:key="order-item-again-{{ $item['id'] }}">
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
                class="min-w-0 rounded-xl border border-zinc-200/70 bg-zinc-50/80 px-3 py-3 dark:border-zinc-800 dark:bg-zinc-900/50 sm:px-4 lg:col-start-2 lg:row-start-1 lg:sticky lg:top-4"
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
</div>
