@props([
    'canAddFunds' => true,
    'addFundsHref' => null,
])

<div class="mt-4 flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-200 px-6 py-10 text-center dark:border-zinc-700" data-test="financial-recent-empty">
    <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
        {{ __('messages.financial_recent_empty_title') }}
    </flux:heading>
    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
        {{ __('messages.financial_recent_empty_hint') }}
    </flux:text>
    @if ($canAddFunds)
        <flux:button
            as="a"
            href="{{ $addFundsHref ?? route('wallet.topup') }}"
            wire:navigate
            variant="primary"
            size="sm"
            class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
        >
            {{ __('messages.wallet_add_funds') }}
        </flux:button>
    @endif
</div>
