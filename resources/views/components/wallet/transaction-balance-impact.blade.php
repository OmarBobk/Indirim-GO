@props([
    'detail' => [],
])

@php
    $detail = is_array($detail) ? $detail : [];
    $before = is_array($detail['balance_before'] ?? null) ? $detail['balance_before'] : [];
    $after = is_array($detail['balance_after'] ?? null) ? $detail['balance_after'] : [];
    $hasSnapshots = (bool) ($detail['has_balance_snapshots'] ?? false);
@endphp

<section
    class="storefront-card storefront-card--pad-md"
    data-test="transaction-balance-impact"
    aria-labelledby="transaction-balance-heading"
>
    <h2 id="transaction-balance-heading" class="storefront-type-section">
        {{ __('messages.transaction_balance_impact') }}
    </h2>

    @if ($hasSnapshots)
        <dl class="mt-4 grid gap-3 sm:grid-cols-2">
            <div>
                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_balance_before') }}</dt>
                <dd class="mt-0.5 text-sm font-medium tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                    {{ $before['formatted'] ?? '—' }}
                </dd>
            </div>
            <div>
                <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_balance_after') }}</dt>
                <dd class="mt-0.5 text-sm font-medium tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                    {{ $after['formatted'] ?? '—' }}
                </dd>
            </div>
        </dl>
    @else
        <p class="mt-3 text-sm text-zinc-600 dark:text-zinc-300" data-test="transaction-balances-unavailable">
            {{ $detail['balances_unavailable_label'] ?? __('messages.transaction_balances_unavailable') }}
        </p>
    @endif
</section>
