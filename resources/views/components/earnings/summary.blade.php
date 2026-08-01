@props([
    'summary' => [],
    'links' => [],
])

@php
    $summary = is_array($summary) ? $summary : [];
    $links = is_array($links) ? $links : [];
@endphp

<section class="storefront-section-stack" data-test="earnings-summary" aria-labelledby="earnings-summary-heading">
    <h2 id="earnings-summary-heading" class="sr-only">{{ __('messages.earnings_summary_heading') }}</h2>

    <div class="grid gap-3 sm:grid-cols-2">
        <div class="storefront-card storefront-card--pad-md" data-test="earnings-credited-card">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('messages.earnings_total_credited') }}</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                {{ $summary['credited']['formatted'] ?? '—' }}
            </p>
            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $summary['credited_in_wallet'] ?? '' }}</p>
            @if (! empty($summary['reversed']['formatted']))
                <p class="mt-2 text-xs text-zinc-500 dark:text-zinc-400" data-test="earnings-reversed-total">
                    {{ $summary['reversed_label'] ?? '' }}:
                    <span class="tabular-nums" dir="ltr">{{ $summary['reversed']['formatted'] }}</span>
                    @if (! empty($summary['waived_back']['formatted']))
                        · {{ $summary['waived_back_label'] ?? '' }}:
                        <span class="tabular-nums" dir="ltr">{{ $summary['waived_back']['formatted'] }}</span>
                    @endif
                    @if (! empty($summary['corrected_back']['formatted']))
                        · {{ $summary['corrected_back_label'] ?? '' }}:
                        <span class="tabular-nums" dir="ltr">{{ $summary['corrected_back']['formatted'] }}</span>
                    @endif
                    · {{ $summary['net_credited_label'] ?? '' }}:
                    <span class="tabular-nums" dir="ltr">{{ $summary['net_credited']['formatted'] ?? '—' }}</span>
                </p>
            @endif
        </div>

        <div class="storefront-card storefront-card--pad-md" data-test="earnings-pending-card">
            <p class="text-xs font-medium text-zinc-500 dark:text-zinc-400">{{ __('messages.earnings_pending_total') }}</p>
            <p class="mt-1 text-xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                {{ $summary['pending']['formatted'] ?? '—' }}
            </p>
            <p class="mt-1 text-xs text-zinc-600 dark:text-zinc-300">{{ $summary['pending_not_spendable'] ?? '' }}</p>
            @if (! empty($summary['has_clawback_debt']) && ! empty($summary['clawback_debt']))
                <p class="mt-2 text-xs text-red-700 dark:text-red-400" data-test="earnings-clawback-debt">
                    {{ $summary['clawback_debt_label'] ?? '' }}:
                    <span class="tabular-nums font-medium" dir="ltr">{{ $summary['clawback_debt']['formatted'] }}</span>
                </p>
                <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">{{ $summary['clawback_debt_hint'] ?? '' }}</p>
            @endif
        </div>
    </div>

    <dl class="grid gap-3 sm:grid-cols-3">
        <div class="storefront-card storefront-card--pad-sm">
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.earnings_eligible') }}</dt>
            <dd class="mt-1 text-sm font-medium tabular-nums" dir="ltr">{{ $summary['eligible']['formatted'] ?? '—' }}</dd>
        </div>
        <div class="storefront-card storefront-card--pad-sm">
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.earnings_credited_this_month') }}</dt>
            <dd class="mt-1 text-sm font-medium tabular-nums" dir="ltr">{{ $summary['credited_this_month']['formatted'] ?? '—' }}</dd>
        </div>
        <div class="storefront-card storefront-card--pad-sm" data-test="earnings-wallet-available">
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ $summary['wallet_label'] ?? __('messages.earnings_wallet_available') }}</dt>
            <dd class="mt-1 text-sm font-medium tabular-nums" dir="ltr">{{ $summary['wallet_available']['formatted'] ?? '—' }}</dd>
            @if (! empty($links['wallet_href']))
                <a href="{{ $links['wallet_href'] }}" wire:navigate class="mt-2 inline-block text-xs font-medium text-(--color-accent) hover:underline">
                    {{ __('messages.earnings_view_wallet') }}
                </a>
            @endif
        </div>
    </dl>

    <p class="text-xs text-zinc-500 dark:text-zinc-400">
        {{ __('messages.earnings_generated_label') }}:
        <span class="tabular-nums" dir="ltr">{{ $summary['generated']['formatted'] ?? '—' }}</span>
        · {{ __('messages.earnings_failed_label') }}:
        <span class="tabular-nums" dir="ltr">{{ $summary['failed']['formatted'] ?? '—' }}</span>
    </p>
</section>
