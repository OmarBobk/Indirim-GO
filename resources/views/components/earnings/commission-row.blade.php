@props([
    'item' => [],
])

@php
    $item = is_array($item) ? $item : [];
    $status = $item['status'] ?? '';
@endphp

<li
    {{ $attributes->class(['flex flex-col gap-2 py-4 sm:flex-row sm:items-start sm:justify-between']) }}
    data-test="earnings-commission-row"
    data-commission-status="{{ $status }}"
>
    <div class="min-w-0 flex-1">
        <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">
            {{ $item['status_label'] ?? '' }}
            @if (! empty($item['is_eligible']) && ! empty($item['eligible_label']))
                <span class="ms-1 text-xs font-normal text-emerald-700 dark:text-emerald-400">{{ $item['eligible_label'] }}</span>
            @endif
        </p>
        <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
            @if (! empty($item['order_number']))
                <span>{{ __('messages.earnings_order_reference') }}:</span>
                <span class="font-mono" dir="ltr">{{ $item['order_number'] }}</span>
            @else
                <span>{{ __('messages.earnings_order_unavailable') }}</span>
            @endif
            <span aria-hidden="true"> · </span>
            <time datetime="{{ $item['created_at'] ?? '' }}">{{ $item['created_at_display'] ?? '' }}</time>
        </p>
        @if (! empty($item['customer_label']))
            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $item['customer_label'] }}</p>
        @endif
        @if (! empty($item['rate_display']))
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.earnings_commission_rate') }}: {{ $item['rate_display'] }}</p>
        @endif
        @if (! empty($item['not_spendable_hint']))
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $item['not_spendable_hint'] }}</p>
        @endif
        @if (! empty($item['anomaly_label']))
            <p class="mt-1 text-xs text-amber-700 dark:text-amber-300" data-test="earnings-anomaly">{{ $item['anomaly_label'] }}</p>
        @endif
        @if (! empty($item['actor_next']))
            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $item['actor_next'] }}</p>
        @endif
        @if (! empty($item['transaction_href']) && ! empty($item['wallet_transaction_public_ref']))
            <p class="mt-2 text-xs">
                <a href="{{ $item['transaction_href'] }}" wire:navigate class="font-medium text-(--color-accent) hover:underline" data-test="earnings-view-transaction">
                    {{ $item['transaction_label'] ?? __('messages.earnings_view_transaction') }}
                    <span class="font-mono" dir="ltr">({{ $item['wallet_transaction_public_ref'] }})</span>
                </a>
            </p>
        @endif
        @if (! empty($item['is_fully_reversed']))
            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300" data-test="earnings-fully-reversed">
                @if (! empty($item['clawback_public_ref']))
                    <span class="font-mono" dir="ltr">{{ $item['clawback_public_ref'] }}</span>
                    <span aria-hidden="true"> · </span>
                @endif
                @if (! empty($item['reversal_href']) && ! empty($item['reversal_wallet_transaction_public_ref']))
                    <a href="{{ $item['reversal_href'] }}" wire:navigate class="font-medium text-(--color-accent) hover:underline">
                        {{ __('messages.earnings_view_reversal') }}
                        <span class="font-mono" dir="ltr">({{ $item['reversal_wallet_transaction_public_ref'] }})</span>
                    </a>
                @endif
            </p>
        @endif
    </div>

    <div class="shrink-0 text-start sm:text-end">
        <p class="text-sm font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
            {{ $item['amount']['formatted'] ?? '—' }}
        </p>
        @if (! empty($item['credited_at_display']))
            <p class="mt-0.5 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('messages.earnings_credited_on') }}
                <time datetime="{{ $item['credited_at'] ?? '' }}">{{ $item['credited_at_display'] }}</time>
            </p>
        @endif
    </div>
</li>
