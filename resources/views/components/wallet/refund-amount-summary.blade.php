@props(['detail' => []])

@php $detail = is_array($detail) ? $detail : []; @endphp

<section class="storefront-card storefront-card--pad-md" data-test="refund-amount-summary" aria-labelledby="refund-amount-heading">
    <flux:heading size="sm" id="refund-amount-heading">{{ __('messages.refund_amount_summary_heading') }}</flux:heading>
    <dl class="mt-3 space-y-2 text-sm">
        <div class="flex flex-wrap justify-between gap-2">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('messages.refund_requested_amount') }}</dt>
            <dd class="font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $detail['amount']['formatted'] ?? '—' }}</dd>
        </div>
        <div class="flex flex-wrap justify-between gap-2">
            <dt class="text-zinc-500 dark:text-zinc-400">{{ __('messages.refund_returned_amount') }}</dt>
            <dd class="font-semibold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">
                {{ ! empty($detail['money_moved']) ? ($detail['amount']['formatted'] ?? '—') : __('messages.refund_not_returned') }}
            </dd>
        </div>
        @if (! empty($detail['requested_at_display']))
            <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('messages.refund_requested_on') }}</dt>
                <dd class="text-zinc-900 dark:text-zinc-100">{{ $detail['requested_at_display'] }}</dd>
            </div>
        @endif
        @if (! empty($detail['posted_at_display']))
            <div class="flex flex-wrap justify-between gap-2">
                <dt class="text-zinc-500 dark:text-zinc-400">{{ __('messages.refund_refunded_on') }}</dt>
                <dd class="text-zinc-900 dark:text-zinc-100">{{ $detail['posted_at_display'] }}</dd>
            </div>
        @endif
    </dl>
</section>
