@props(['detail' => []])

@php $detail = is_array($detail) ? $detail : []; @endphp

<section class="space-y-3" data-test="topup-recovery" aria-label="{{ __('messages.topup_recovery_a11y') }}">
    @if (! empty($detail['can_retry']) && ! empty($detail['retry_href']))
        <flux:button
            as="a"
            href="{{ $detail['retry_href'] }}"
            wire:navigate
            variant="primary"
            class="w-full !bg-accent !text-accent-foreground hover:!bg-accent-hover sm:w-auto"
            data-test="topup-retry"
        >
            {{ __('messages.topup_start_corrected') }}
        </flux:button>
    @endif

    @if (! empty($detail['posted_transaction_public_ref']))
        <div class="rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/50">
            <p class="text-sm text-zinc-700 dark:text-zinc-300">
                {{ __('messages.topup_posted_transaction_label') }}:
                <span class="font-mono" dir="ltr">{{ $detail['posted_transaction_public_ref'] }}</span>
            </p>
            @if (! empty($detail['ledger_href']))
                <a href="{{ $detail['ledger_href'] }}" wire:navigate class="mt-2 inline-block text-sm font-medium text-(--color-accent) hover:underline" data-test="topup-view-ledger">
                    {{ __('messages.topup_view_transaction') }}
                </a>
            @endif
        </div>
    @endif
</section>
