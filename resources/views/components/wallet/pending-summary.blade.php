@props([
    'pending' => [],
])

@php
    $pending = is_array($pending) ? $pending : [];
    $items = $pending['items'] ?? [];
    $isEmpty = (bool) ($pending['is_empty'] ?? true);
@endphp

@unless ($isEmpty)
    <section
        class="storefront-card storefront-card--pad-md"
        data-test="financial-pending-summary"
        aria-labelledby="financial-pending-heading"
    >
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="sm" id="financial-pending-heading" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.financial_pending_heading') }}
            </flux:heading>
            @if (($pending['has_more'] ?? false) && ($pending['view_all_href'] ?? null))
                <a
                    href="{{ $pending['view_all_href'] }}"
                    wire:navigate
                    class="text-sm font-medium text-(--color-accent) hover:underline"
                    data-test="financial-pending-view-all"
                >
                    {{ __('messages.financial_pending_view_all') }}
                </a>
            @endif
        </div>

        <ul class="mt-3 space-y-2" role="list">
            @foreach ($items as $item)
                <x-wallet.pending-item :item="$item" wire:key="pending-{{ $item['id'] }}" />
            @endforeach
        </ul>
    </section>
@endunless
