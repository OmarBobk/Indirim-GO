@props([
    'balance' => null,
    'busy' => false,
])

@php
    $balance = is_array($balance) ? $balance : null;
@endphp

<section
    {{ $attributes->class(['storefront-card storefront-card--pad-md']) }}
    data-test="wallet-spend-summary"
    aria-labelledby="financial-available-heading"
    @if ($busy) aria-busy="true" @endif
>
    @if ($balance === null)
        <div class="animate-pulse space-y-3" data-test="financial-balance-skeleton">
            <div class="h-3 w-32 rounded bg-zinc-200 dark:bg-zinc-700"></div>
            <div class="h-10 w-48 rounded bg-zinc-200 dark:bg-zinc-700"></div>
            <div class="grid grid-cols-2 gap-3 pt-2">
                <div class="h-12 rounded bg-zinc-100 dark:bg-zinc-800"></div>
                <div class="h-12 rounded bg-zinc-100 dark:bg-zinc-800"></div>
            </div>
        </div>
    @else
        <p
            id="financial-available-heading"
            class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400"
        >
            {{ $balance['labels']['available'] }}
        </p>
        <p
            class="mt-2 text-4xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100"
            dir="ltr"
            data-test="wallet-available-to-spend"
        >
            {{ $balance['available_to_spend']['formatted'] ?? '—' }}
        </p>

        <dl class="mt-5 grid gap-3 border-t border-zinc-100 pt-4 dark:border-zinc-800 sm:grid-cols-2">
            <div data-test="wallet-prepaid-balance">
                <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    {{ $balance['labels']['prepaid'] }}
                </dt>
                <dd class="mt-1 text-base font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                    {{ $balance['prepaid_balance']['formatted'] ?? '—' }}
                </dd>
            </div>

            @if ($balance['outstanding_debt'] !== null)
                <div data-test="wallet-outstanding-debt">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ $balance['labels']['debt'] }}
                    </dt>
                    <dd class="mt-1 text-base font-semibold tabular-nums text-red-700 dark:text-red-400" dir="ltr">
                        {{ $balance['outstanding_debt']['formatted'] }}
                        <span class="sr-only">{{ __('messages.financial_debt_a11y') }}</span>
                    </dd>
                </div>
            @endif

            @if ($balance['credit_facility_active'] && $balance['remaining_credit'] !== null)
                <div data-test="wallet-available-credit">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ $balance['labels']['remaining_credit'] }}
                    </dt>
                    <dd class="mt-1 text-base font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                        {{ $balance['remaining_credit']['formatted'] }}
                    </dd>
                </div>
            @endif

            @if ($balance['credit_facility_active'] && $balance['credit_limit'] !== null)
                <div data-test="wallet-credit-limit">
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ $balance['labels']['credit_limit'] }}
                    </dt>
                    <dd class="mt-1 text-base font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                        {{ $balance['credit_limit']['formatted'] }}
                    </dd>
                </div>
            @endif
        </dl>

        @unless ($balance['available_to_spend']['visible'] ?? true)
            <div class="mt-4">
                <x-purchase.prices-gated context="wallet" />
            </div>
        @endunless
    @endif
</section>
