@props([
    'payout' => [],
])

@php
    $payout = is_array($payout) ? $payout : [];
@endphp

<section class="storefront-card storefront-card--pad-md" data-test="earnings-payout-request" aria-labelledby="earnings-payout-heading">
    <h2 id="earnings-payout-heading" class="storefront-type-section">{{ __('messages.earnings_payout_request') }}</h2>
    <p class="mt-1 text-sm text-zinc-600 dark:text-zinc-300">{{ $payout['hint'] ?? '' }}</p>

    <dl class="mt-3 grid gap-2 text-sm sm:grid-cols-2">
        <div>
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.earnings_minimum_payout') }}</dt>
            <dd class="tabular-nums" dir="ltr">{{ $payout['threshold']['formatted'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.earnings_hold_days') }}</dt>
            <dd>{{ $payout['wait_days'] ?? 0 }}</dd>
        </div>
    </dl>

    @if (! empty($payout['status_label']))
        <p class="mt-3 text-sm" data-test="earnings-payout-status">
            <span class="font-medium">{{ $payout['status_label'] }}</span>
            @if (! empty($payout['request_amount']['formatted']))
                <span class="tabular-nums text-zinc-600 dark:text-zinc-300" dir="ltr">· {{ $payout['request_amount']['formatted'] }}</span>
            @endif
        </p>
    @endif

    @if (! empty($payout['can_request']))
        <div class="mt-4">
            <flux:button
                type="button"
                variant="primary"
                wire:click="requestPayout"
                wire:confirm="{{ $payout['confirm'] ?? __('messages.earnings_payout_request_confirm') }}"
                data-test="earnings-request-payout"
            >
                {{ __('messages.earnings_request_payout') }}
            </flux:button>
        </div>
    @endif
</section>
