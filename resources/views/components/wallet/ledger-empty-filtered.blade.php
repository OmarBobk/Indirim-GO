@props([
    'search' => '',
])

<div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed border-zinc-200 px-6 py-10 text-center dark:border-zinc-700" data-test="financial-ledger-empty-filtered">
    <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
        {{ __('messages.financial_ledger_empty_filtered_title') }}
    </flux:heading>
    @if (filled($search))
        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('messages.financial_ledger_empty_search_hint', ['query' => $search]) }}
        </flux:text>
    @endif
    <button
        type="button"
        wire:click="clearFilters"
        class="text-sm font-medium text-(--color-accent) hover:underline"
        data-test="financial-ledger-empty-clear"
    >
        {{ __('messages.financial_ledger_clear_filters') }}
    </button>
</div>
