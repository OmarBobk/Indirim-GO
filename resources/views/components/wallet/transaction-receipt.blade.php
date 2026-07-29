@props([
    'detail' => [],
])

@php
    $detail = is_array($detail) ? $detail : [];
    $receipt = is_array($detail['receipt'] ?? null) ? $detail['receipt'] : [];
    $amount = is_array($detail['amount'] ?? null) ? $detail['amount'] : [];
    $before = is_array($detail['balance_before'] ?? null) ? $detail['balance_before'] : [];
    $after = is_array($detail['balance_after'] ?? null) ? $detail['balance_after'] : [];
    $source = is_array($detail['source'] ?? null) ? $detail['source'] : [];
@endphp

<section
    class="transaction-receipt storefront-card storefront-card--pad-md"
    data-test="transaction-receipt"
    aria-labelledby="transaction-receipt-heading"
>
    <div class="transaction-receipt__brand">
        <p class="text-lg font-semibold tracking-tight text-zinc-900 dark:text-zinc-100">
            {{ $receipt['brand'] ?? config('app.name') }}
        </p>
        <h2 id="transaction-receipt-heading" class="mt-1 text-base font-medium text-zinc-800 dark:text-zinc-200">
            {{ $receipt['title'] ?? __('messages.transaction_receipt_title') }}
        </h2>
    </div>

    <dl class="transaction-receipt__facts mt-4 grid gap-2 text-sm">
        <div class="flex justify-between gap-4">
            <dt>{{ $receipt['reference_label'] ?? __('messages.transaction_reference') }}</dt>
            <dd class="font-mono" dir="ltr">{{ $detail['public_reference'] ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt>{{ $receipt['type_label'] ?? __('messages.transaction_type') }}</dt>
            <dd>{{ $detail['type_label'] ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt>{{ $receipt['direction_label'] ?? __('messages.transaction_direction') }}</dt>
            <dd>{{ $detail['direction_label'] ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt>{{ $receipt['amount_label'] ?? __('messages.transaction_amount') }}</dt>
            <dd class="font-semibold tabular-nums" dir="ltr">{{ $amount['formatted'] ?? '—' }}</dd>
        </div>
        <div class="flex justify-between gap-4">
            <dt>{{ $receipt['posted_label'] ?? __('messages.transaction_posted_on') }}</dt>
            <dd><time datetime="{{ $detail['posted_at'] ?? '' }}">{{ $detail['posted_at_display'] ?? '—' }}</time></dd>
        </div>
        @if (! empty($detail['has_balance_snapshots']))
            <div class="flex justify-between gap-4">
                <dt>{{ $receipt['balance_before_label'] ?? __('messages.transaction_balance_before') }}</dt>
                <dd class="tabular-nums" dir="ltr">{{ $before['formatted'] ?? '—' }}</dd>
            </div>
            <div class="flex justify-between gap-4">
                <dt>{{ $receipt['balance_after_label'] ?? __('messages.transaction_balance_after') }}</dt>
                <dd class="tabular-nums" dir="ltr">{{ $after['formatted'] ?? '—' }}</dd>
            </div>
        @endif
        @if (! empty($source['order_number']))
            <div class="flex justify-between gap-4">
                <dt>{{ __('messages.transaction_related_order') }}</dt>
                <dd class="font-mono" dir="ltr">{{ $source['order_number'] }}</dd>
            </div>
        @endif
        @if (! empty($source['topup_public_ref']))
            <div class="flex justify-between gap-4">
                <dt>{{ __('messages.transaction_related_topup') }}</dt>
                <dd class="font-mono" dir="ltr">{{ $source['topup_public_ref'] }}</dd>
            </div>
        @endif
        @if (! empty($source['product_label']))
            <div class="flex justify-between gap-4">
                <dt>{{ __('messages.transaction_product_summary') }}</dt>
                <dd>{{ $source['product_label'] }}</dd>
            </div>
        @endif
        @if (! empty($source['customer_reason']))
            <div class="flex justify-between gap-4">
                <dt>{{ __('messages.transaction_adjustment_reason') }}</dt>
                <dd>{{ $source['customer_reason'] }}</dd>
            </div>
        @endif
    </dl>

    <p class="transaction-receipt__disclaimer mt-6 text-xs text-zinc-600 dark:text-zinc-400">
        {{ $receipt['disclaimer'] ?? __('messages.transaction_receipt_disclaimer') }}
    </p>
    <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-500">
        {{ $receipt['generated_on_label'] ?? __('messages.transaction_receipt_generated_on') }}:
        <time>{{ $receipt['generated_on'] ?? '' }}</time>
        <span class="sr-only">· v{{ $receipt['version'] ?? 1 }}</span>
    </p>
</section>
