@props([
    'recent' => [],
    'actions' => [],
])

@php
    $recent = is_array($recent) ? $recent : [];
    $items = $recent['items'] ?? [];
    $isEmpty = (bool) ($recent['is_empty'] ?? true);
    $canAddFunds = (bool) (($actions['can_add_funds'] ?? true));
    $viewAllHref = $actions['view_transactions_href'] ?? null;
@endphp

<section
    class="storefront-card storefront-card--pad-md"
    data-test="financial-recent-transactions"
    aria-labelledby="financial-recent-heading"
>
    <div class="flex items-center justify-between gap-3">
        <flux:heading size="sm" id="financial-recent-heading" class="text-zinc-900 dark:text-zinc-100">
            {{ __('messages.financial_recent_heading') }}
        </flux:heading>
        @if (is_string($viewAllHref) && $viewAllHref !== '' && ! $isEmpty)
            <a
                href="{{ $viewAllHref }}"
                wire:navigate
                class="text-sm font-medium text-(--color-accent) hover:underline"
                data-test="financial-view-transactions"
            >
                {{ __('messages.financial_view_transactions') }}
            </a>
        @endif
    </div>

    @if ($isEmpty)
        <x-wallet.empty-recent-transactions :can-add-funds="$canAddFunds" :add-funds-href="$actions['add_funds_href'] ?? route('wallet.topup')" />
    @else
        <ul class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800" role="list">
            @foreach ($items as $item)
                <x-wallet.transaction-row :item="$item" wire:key="tx-{{ $item['id'] }}" />
            @endforeach
        </ul>
    @endif
</section>
