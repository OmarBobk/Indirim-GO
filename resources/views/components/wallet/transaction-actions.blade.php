@props([
    'detail' => [],
])

@php
    $detail = is_array($detail) ? $detail : [];
    $actions = is_array($detail['actions'] ?? null) ? $detail['actions'] : [];
@endphp

<section
    {{ $attributes->class(['flex flex-wrap items-center gap-3']) }}
    data-test="transaction-actions"
    aria-label="{{ __('messages.transaction_actions_heading') }}"
>
    <flux:button
        type="button"
        variant="primary"
        data-test="transaction-print-button"
        aria-label="{{ $actions['print_a11y'] ?? __('messages.transaction_print_receipt_a11y') }}"
        onclick="window.print()"
    >
        {{ $actions['print_label'] ?? __('messages.transaction_print_receipt') }}
    </flux:button>

    @if (! empty($actions['source_href']) && ! empty($actions['source_label']))
        <a
            href="{{ $actions['source_href'] }}"
            wire:navigate
            class="text-sm font-medium text-(--color-accent) hover:underline"
            data-test="transaction-primary-source-action"
        >
            {{ $actions['source_label'] }}
        </a>
    @endif
</section>
