@props([
    'addFundsHref' => null,
])

<div class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center" data-test="topups-empty">
    <flux:heading size="sm">{{ __('messages.topups_empty_title') }}</flux:heading>
    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('messages.topups_empty_hint') }}</flux:text>
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
</div>
