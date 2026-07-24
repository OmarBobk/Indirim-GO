@props([
    'href',
    'formattedTotal',
    'orderNumber',
    'formattedDate',
    'status',
    'summary',
    'lines',
    'showPrices' => true,
    'refundSummary' => null,
])

{{-- Back-compat alias → Milestone 4 orders card --}}
<x-orders.card
    :href="$href"
    :formatted-total="$formattedTotal"
    :order-number="$orderNumber"
    :formatted-date="$formattedDate"
    :status="$status"
    :summary="$summary"
    :lines="$lines"
    :show-prices="$showPrices"
    :refund-summary="$refundSummary"
/>
