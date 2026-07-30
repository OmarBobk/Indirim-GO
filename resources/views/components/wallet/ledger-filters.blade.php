@props([
    'direction' => 'all',
    'type' => 'all',
    'search' => '',
    'dateFrom' => '',
    'dateTo' => '',
    'showCommission' => false,
    'hasActive' => false,
])

@php
    $directionOptions = [
        'all' => __('messages.financial_ledger_filter_all'),
        'credit' => __('messages.financial_ledger_filter_money_in'),
        'debit' => __('messages.financial_ledger_filter_money_out'),
    ];

    $typeOptions = [
        'all' => __('messages.financial_ledger_filter_all_types'),
        'purchase' => __('messages.financial_ledger_filter_purchases'),
        'topup' => __('messages.financial_ledger_filter_topups'),
        'refund' => __('messages.financial_ledger_filter_refunds'),
        'adjustment' => __('messages.financial_ledger_filter_adjustments'),
    ];

    if ($showCommission) {
        $typeOptions['commission_credit'] = __('messages.financial_ledger_filter_commission');
    }
@endphp

<div class="space-y-3" data-test="financial-ledger-filters">
    <nav
        class="flex gap-2 overflow-x-auto pb-1"
        aria-label="{{ __('messages.financial_ledger_filters_label') }}"
        data-test="financial-ledger-direction-filters"
    >
        @foreach ($directionOptions as $value => $label)
            @php $isActive = $direction === $value; @endphp
            <button
                type="button"
                wire:click="setDirection('{{ $value }}')"
                @class([
                    'shrink-0 rounded-full border px-4 py-2 text-sm font-semibold transition data-loading:pointer-events-none data-loading:opacity-60',
                    'border-(--color-accent) bg-(--color-accent) text-(--color-accent-foreground)' => $isActive,
                    'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200' => ! $isActive,
                ])
                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                data-test="ledger-direction-{{ $value }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <nav
        class="flex gap-2 overflow-x-auto pb-1"
        aria-label="{{ __('messages.financial_ledger_type_filters_label') }}"
        data-test="financial-ledger-type-filters"
    >
        @foreach ($typeOptions as $value => $label)
            @php $isActive = $type === $value; @endphp
            <button
                type="button"
                wire:click="setType('{{ $value }}')"
                @class([
                    'shrink-0 rounded-full border px-3 py-1.5 text-sm font-medium transition data-loading:pointer-events-none data-loading:opacity-60',
                    'border-(--color-accent) bg-(--color-accent)/15 text-zinc-900 dark:text-zinc-100' => $isActive,
                    'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-300' => ! $isActive,
                ])
                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                data-test="ledger-type-{{ $value }}"
            >
                {{ $label }}
            </button>
        @endforeach
    </nav>

    <div class="grid gap-3 sm:grid-cols-[1fr_auto_auto]">
        <div>
            <label for="ledger-search" class="sr-only">{{ __('messages.financial_ledger_search_label') }}</label>
            <flux:input
                id="ledger-search"
                type="search"
                wire:model.live.debounce.400ms="search"
                :placeholder="__('messages.financial_ledger_search_placeholder')"
                data-test="financial-ledger-search"
            />
        </div>
        <div>
            <label for="ledger-from" class="sr-only">{{ __('messages.financial_ledger_date_from') }}</label>
            <flux:input
                id="ledger-from"
                type="date"
                wire:model.live="dateFrom"
                data-test="financial-ledger-date-from"
            />
        </div>
        <div>
            <label for="ledger-to" class="sr-only">{{ __('messages.financial_ledger_date_to') }}</label>
            <flux:input
                id="ledger-to"
                type="date"
                wire:model.live="dateTo"
                data-test="financial-ledger-date-to"
            />
        </div>
    </div>

    @if ($hasActive)
        <div>
            <button
                type="button"
                wire:click="clearFilters"
                class="text-sm font-medium text-(--color-accent) hover:underline"
                data-test="financial-ledger-clear-filters"
            >
                {{ __('messages.financial_ledger_clear_filters') }}
            </button>
        </div>
    @endif
</div>
