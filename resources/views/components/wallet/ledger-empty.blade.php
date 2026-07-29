<div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-200 px-6 py-12 text-center dark:border-zinc-700" data-test="financial-ledger-empty">
    <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
        {{ __('messages.financial_ledger_empty_title') }}
    </flux:heading>
    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
        {{ __('messages.financial_ledger_empty_hint') }}
    </flux:text>
    <div class="flex flex-wrap items-center justify-center gap-3">
        <flux:button
            as="a"
            href="{{ route('wallet.topup') }}"
            wire:navigate
            variant="primary"
            size="sm"
            class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
            data-test="financial-ledger-empty-add-funds"
        >
            {{ __('messages.wallet_add_funds') }}
        </flux:button>
        <a href="{{ route('home') }}" wire:navigate class="text-sm font-medium text-zinc-600 underline-offset-2 hover:underline dark:text-zinc-400">
            {{ __('messages.financial_ledger_browse_products') }}
        </a>
    </div>
</div>
