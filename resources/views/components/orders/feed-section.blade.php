@props([
    /** @var \Illuminate\Support\Collection<int, \App\Models\Order> $orders */
    'orders',
    /** @var \App\Support\CustomerOrderCardPresenter $presenter */
    'presenter',
    'title' => null,
    'hint' => null,
    'section' => 'orders',
])

<section class="space-y-3 sm:space-y-4" data-test="orders-{{ $section }}-section">
    @if ($title !== null)
        <header class="space-y-1">
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                {{ $title }}
            </flux:heading>
            @if ($hint !== null)
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ $hint }}
                </flux:text>
            @endif
        </header>
    @endif

    <div class="space-y-4 sm:space-y-5" data-test="orders-{{ $section }}-list">
        @foreach ($orders as $order)
            @php
                $card = $presenter->present($order);
            @endphp
            <div wire:key="{{ $section }}-order-{{ $order->id }}">
                <x-orders.card
                    :href="$card['href']"
                    :formatted-total="$card['formattedTotal']"
                    :order-number="$card['orderNumber']"
                    :formatted-date="$card['formattedDate']"
                    :status="$card['status']"
                    :summary="$card['summary']"
                    :lines="$card['lines']"
                    :show-prices="$card['showPrices']"
                    :refund-summary="$card['refundSummary']"
                />
            </div>
        @endforeach
    </div>
</section>
