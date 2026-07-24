@props([])

<div
    role="search"
    data-test="orders-search"
    data-section="orders-search"
>
    <flux:input
        type="search"
        name="search"
        wire:model.live.debounce.300ms="search"
        :placeholder="__('messages.orders_customer_search_placeholder')"
        icon="magnifying-glass"
        autocomplete="off"
        aria-label="{{ __('messages.orders_customer_search_label') }}"
        data-event="orders-search"
        class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
    />
</div>
