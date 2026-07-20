@props([
    'wallet',
    'money',
    'pricesVisible' => true,
    'showCreditFacility' => true,
])

@php
    $display = \App\Support\CustomerWalletDisplay::for($wallet, auth()->user());
    $availableToSpend = $wallet->availableToSpend();
    $outstandingDebt = $wallet->outstandingDebt();
    $isOverdrawn = $wallet->isOverdrawn();
    $creditGranted = $display->isCreditGranted();
    $creditActive = $display->isCreditActive();
    $tone = $display->tone();
@endphp

<div {{ $attributes->class(['space-y-6']) }}>
    <section
        class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6"
        data-test="wallet-spend-summary"
        data-wallet-tone="{{ $tone }}"
    >
        <div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between">
            <div class="min-w-0">
                @if ($isOverdrawn)
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.wallet_you_owe') }}
                    </flux:heading>
                    <div
                        class="mt-3 text-3xl font-semibold tabular-nums text-red-700 dark:text-red-400"
                        dir="ltr"
                        data-test="wallet-outstanding-debt"
                    >
                        @if ($pricesVisible)
                            {{ $money->format((float) $outstandingDebt, 'USD', 2) }}
                        @else
                            —
                        @endif
                    </div>
                    <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $creditActive
                            ? __('messages.wallet_you_owe_hint')
                            : __('messages.wallet_you_owe_no_credit_hint') }}
                    </flux:text>
                @else
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.wallet_prepaid_balance') }}
                    </flux:heading>
                    <div
                        @class([
                            'mt-3 text-3xl font-semibold tabular-nums',
                            'text-emerald-700 dark:text-emerald-400' => $tone === 'positive',
                            'text-zinc-700 dark:text-zinc-300' => $tone === 'zero',
                        ])
                        dir="ltr"
                        data-test="wallet-prepaid-balance"
                    >
                        @if ($pricesVisible)
                            {{ $money->format((float) $wallet->balance, 'USD', 2) }}
                        @else
                            —
                        @endif
                    </div>
                    <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                        {{ $creditActive
                            ? __('messages.wallet_prepaid_with_credit_hint')
                            : __('messages.wallet_balance_hint') }}
                    </flux:text>
                @endif
            </div>

            @if ($slot->isNotEmpty())
                <div class="shrink-0">
                    {{ $slot }}
                </div>
            @endif
        </div>

        @if ($pricesVisible)
            <div class="mt-5 grid gap-3 border-t border-zinc-100 pt-5 dark:border-zinc-800 sm:grid-cols-2">
                <div data-test="wallet-available-to-spend">
                    <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.wallet_available_to_spend') }}
                    </p>
                    <p
                        @class([
                            'mt-1 text-lg font-semibold tabular-nums',
                            'text-emerald-700 dark:text-emerald-400' => bccomp($availableToSpend, '0', 2) === 1,
                            'text-zinc-700 dark:text-zinc-300' => bccomp($availableToSpend, '0', 2) !== 1,
                        ])
                        dir="ltr"
                    >
                        {{ $money->format((float) $availableToSpend, 'USD', 2) }}
                    </p>
                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                        @if ($isOverdrawn && $creditActive)
                            {{ __('messages.wallet_remaining_credit_hint') }}
                        @elseif ($creditActive)
                            {{ __('messages.wallet_available_includes_credit_hint') }}
                        @else
                            {{ __('messages.wallet_available_to_spend_hint') }}
                        @endif
                    </p>
                </div>

                @if ($creditActive)
                    <div data-test="wallet-credit-limit-summary">
                        <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ __('messages.wallet_credit_limit_label') }}
                        </p>
                        <p class="mt-1 text-lg font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                            {{ $money->format((float) $wallet->credit_limit, 'USD', 2) }}
                        </p>
                        <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">
                            {{ __('messages.wallet_credit_limit_hint') }}
                        </p>
                    </div>
                @endif
            </div>
        @endif
    </section>

    @if ($showCreditFacility && $creditGranted && $pricesVisible)
        <section
            class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6"
            data-test="wallet-credit-facility"
        >
            <div class="flex flex-wrap items-center justify-between gap-3">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.wallet_credit_section') }}
                </flux:heading>
                <flux:badge
                    color="{{ $creditActive ? 'green' : 'amber' }}"
                    data-test="wallet-credit-status"
                >
                    {{ $creditActive
                        ? __('messages.wallet_credit_status_active')
                        : __('messages.wallet_credit_status_suspended') }}
                </flux:badge>
            </div>
            <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                {{ $creditActive
                    ? __('messages.wallet_credit_active_hint')
                    : __('messages.wallet_credit_suspended_hint') }}
            </flux:text>
            <dl class="mt-4 grid gap-3 sm:grid-cols-2">
                <div>
                    <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.wallet_credit_limit_label') }}
                    </dt>
                    <dd class="mt-1 text-base font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr" data-test="wallet-credit-limit">
                        {{ $money->format((float) $wallet->credit_limit, 'USD', 2) }}
                    </dd>
                </div>
                @if ($creditActive)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ __('messages.wallet_available_credit_label') }}
                        </dt>
                        <dd class="mt-1 text-base font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr" data-test="wallet-available-credit">
                            {{ $money->format((float) $wallet->availableCredit(), 'USD', 2) }}
                        </dd>
                    </div>
                @endif
                @if ($wallet->payment_terms_days)
                    <div>
                        <dt class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                            {{ __('messages.wallet_credit_terms_label') }}
                        </dt>
                        <dd class="mt-1 text-base font-semibold text-zinc-900 dark:text-zinc-100" data-test="wallet-credit-terms">
                            {{ __('messages.wallet_credit_terms_net', ['days' => $wallet->payment_terms_days]) }}
                        </dd>
                    </div>
                @endif
            </dl>
        </section>
    @endif
</div>
