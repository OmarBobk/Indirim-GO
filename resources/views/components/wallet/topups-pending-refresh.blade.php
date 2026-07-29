<div
    x-data
    x-cloak
    x-show="$wire.hasPendingRefresh"
    x-bind:aria-hidden="$wire.hasPendingRefresh ? 'false' : 'true'"
    class="flex flex-col gap-3 rounded-xl border border-sky-200 bg-sky-50/80 p-4 dark:border-sky-800 dark:bg-sky-950/30 sm:flex-row sm:items-center sm:justify-between"
    role="status"
    aria-live="polite"
    data-test="topups-pending-refresh"
>
    <flux:text class="text-sm text-zinc-800 dark:text-zinc-100">
        {{ __('messages.topups_updates_available') }}
    </flux:text>
    <flux:button
        variant="primary"
        size="sm"
        wire:click="applyPendingRefresh"
        wire:loading.attr="disabled"
        wire:target="applyPendingRefresh"
        data-test="topups-return-latest"
    >
        <span wire:loading.remove wire:target="applyPendingRefresh">{{ __('messages.topups_return_latest') }}</span>
        <span wire:loading wire:target="applyPendingRefresh">{{ __('messages.please_wait') }}</span>
    </flux:button>
</div>
