@props([
    /** @var \Illuminate\Contracts\Pagination\LengthAwarePaginator<\App\Models\Order> $orders */
    'orders',
    /** @var \App\Support\CustomerOrderCardPresenter $presenter */
    'presenter',
    'filter' => \App\Support\CustomerOrderCardPresenter::FILTER_ALL,
    /** @var array{title: string, hint: string} $emptyState */
    'emptyState',
])

@if ($orders->isEmpty())
    <x-orders.empty
        :title="$emptyState['title']"
        :hint="$emptyState['hint']"
        :show-home-action="$emptyState['showHomeAction'] ?? ($filter === \App\Support\CustomerOrderCardPresenter::FILTER_ALL)"
    />
@else
    @php
        $pageOrders = collect($orders->items());
        [$attentionOrders, $regularOrders] = $filter === \App\Support\CustomerOrderCardPresenter::FILTER_ALL
            ? $pageOrders->partition(fn (\App\Models\Order $order) => $presenter->needsAttention($order))
            : [collect(), $pageOrders];
    @endphp

    <div class="space-y-7 sm:space-y-8" data-test="orders-list" data-section="orders-feed">
        @if ($attentionOrders->isNotEmpty())
            <x-orders.feed-section
                :orders="$attentionOrders"
                :presenter="$presenter"
                :title="__('messages.orders_needs_attention_section')"
                :hint="__('messages.orders_needs_attention_section_hint')"
                section="attention"
            />
        @endif

        @if ($regularOrders->isNotEmpty())
            <x-orders.feed-section
                :orders="$regularOrders"
                :presenter="$presenter"
                :title="$attentionOrders->isNotEmpty() ? __('messages.orders_recent_section') : null"
                section="regular"
            />
        @endif
    </div>

    <div class="pt-2" data-test="orders-pagination">
        {{ $orders->links() }}
    </div>
@endif
