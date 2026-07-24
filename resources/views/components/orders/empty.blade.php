@props([
    'title' => null,
    'hint' => null,
    'showHomeAction' => true,
])

<x-storefront.empty
    icon="shopping-bag"
    :title="$title ?? __('messages.no_orders')"
    :description="$hint ?? __('messages.no_orders_hint')"
    data-test="orders-empty"
>
    <x-slot:actions>
        @if ($showHomeAction)
            <flux:button
                variant="primary"
                icon="home"
                href="{{ route('home') }}"
                wire:navigate
                class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
            >
                {{ __('messages.homepage') }}
            </flux:button>
        @endif
    </x-slot:actions>
</x-storefront.empty>
