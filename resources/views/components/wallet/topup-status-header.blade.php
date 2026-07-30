@props(['detail' => []])

@php $detail = is_array($detail) ? $detail : []; @endphp

<section class="storefront-card storefront-card--pad-md" data-test="topup-status-header" aria-labelledby="topup-status-heading">
    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        <div>
            <p id="topup-status-heading" class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {{ __('messages.topup_reference_label') }}
            </p>
            <p class="mt-1 font-mono text-lg font-semibold text-zinc-900 dark:text-zinc-100" dir="ltr">
                {{ $detail['public_reference'] ?? '' }}
            </p>
            <div class="mt-2 flex flex-wrap items-center gap-2">
                <flux:badge color="{{ $detail['badge_color'] ?? 'zinc' }}">{{ $detail['status_label'] ?? '' }}</flux:badge>
                <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $detail['actor_label'] ?? '' }}</span>
            </div>
            @if (! empty($detail['customer_safe_reason']))
                <p class="mt-3 text-sm text-zinc-700 dark:text-zinc-300">{{ $detail['customer_safe_reason'] }}</p>
            @endif
            @if (! empty($detail['is_integrity_anomaly']))
                <p class="mt-3 text-sm text-amber-700 dark:text-amber-300">{{ __('messages.topup_integrity_anomaly_hint') }}</p>
            @endif
        </div>
        <div class="text-start sm:text-end">
            <p class="text-xs font-medium uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                {{ __('messages.topup_amount_credited_label') }}
            </p>
            <p class="mt-1 text-2xl font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                {{ $detail['amount']['formatted'] ?? '—' }}
            </p>
            <p class="mt-1 text-xs text-zinc-500 dark:text-zinc-400">
                {{ __('messages.topup_money_moved_label') }}:
                {{ ! empty($detail['money_moved']) ? __('messages.yes') : __('messages.no') }}
            </p>
        </div>
    </div>
</section>
