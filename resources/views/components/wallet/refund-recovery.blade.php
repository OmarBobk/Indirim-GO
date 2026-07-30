@props(['detail' => []])

@php $detail = is_array($detail) ? $detail : []; @endphp

<section class="space-y-3" data-test="refund-recovery" aria-label="{{ __('messages.refund_recovery_a11y') }}">
    @if (! empty($detail['can_recover']) && ! empty($detail['recovery_href']))
        <flux:button
            as="a"
            href="{{ $detail['recovery_href'] }}"
            wire:navigate
            variant="primary"
            class="w-full !bg-accent !text-accent-foreground hover:!bg-accent-hover sm:w-auto"
            data-test="refund-recover"
        >
            {{ __('messages.refund_review_on_order') }}
        </flux:button>
        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('messages.refund_recover_hint') }}
        </flux:text>
    @elseif (! empty($detail['is_integrity_anomaly']))
        <flux:text class="text-sm text-amber-700 dark:text-amber-300">
            {{ __('messages.refund_integrity_anomaly_hint') }}
        </flux:text>
    @endif

    @if (! empty($detail['money_moved']) && ! empty($detail['ledger_href']))
        <div class="rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/50">
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ __('messages.refund_posted_transaction_label') }}:
                <span class="font-mono" dir="ltr">{{ $detail['public_reference'] ?? '' }}</span>
            </p>
            <a href="{{ $detail['ledger_href'] }}" wire:navigate class="mt-2 inline-block text-sm font-medium text-(--color-accent) hover:underline" data-test="refund-view-ledger">
                {{ __('messages.refund_view_transaction') }}
            </a>
        </div>
    @endif
</section>
