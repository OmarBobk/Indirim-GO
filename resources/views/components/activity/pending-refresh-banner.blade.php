{{--
    Always mounted so page-2+ skipRender invalidations can reveal the banner via $wire
    without re-evaluating the Activity feed.
--}}
<div
    x-data
    x-cloak
    x-show="$wire.hasPendingRefresh"
    x-bind:aria-hidden="$wire.hasPendingRefresh ? 'false' : 'true'"
    class="flex flex-col gap-3 rounded-xl border border-sky-200 bg-sky-50/80 p-4 dark:border-sky-800 dark:bg-sky-950/30 sm:flex-row sm:items-center sm:justify-between"
    role="status"
    aria-live="polite"
    data-test="activity-pending-refresh-banner"
>
    <flux:text class="text-sm text-zinc-800 dark:text-zinc-100">
        {{ __('messages.activity_new_activity_available') }}
    </flux:text>
    <flux:button
        variant="primary"
        size="sm"
        wire:click="applyPendingRefresh"
        wire:loading.attr="disabled"
        wire:target="applyPendingRefresh"
        data-test="activity-pending-refresh-button"
    >
        <span wire:loading.remove wire:target="applyPendingRefresh">{{ __('messages.activity_refresh_feed') }}</span>
        <span wire:loading wire:target="applyPendingRefresh">{{ __('messages.please_wait') }}</span>
    </flux:button>
</div>
