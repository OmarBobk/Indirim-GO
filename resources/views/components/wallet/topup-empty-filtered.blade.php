@props(['search' => ''])

<div class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center" data-test="topups-empty-filtered">
    <flux:heading size="sm">{{ __('messages.topups_empty_filtered_title') }}</flux:heading>
    @if (filled($search))
        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('messages.topups_empty_filtered_search', ['search' => $search]) }}
        </flux:text>
    @endif
    <flux:button type="button" variant="ghost" size="sm" wire:click="clearFilters">
        {{ __('messages.topups_clear_filters') }}
    </flux:button>
</div>
