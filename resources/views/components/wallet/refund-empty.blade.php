@props(['ordersHref' => null])

<div class="flex flex-col items-center justify-center gap-3 px-6 py-12 text-center" data-test="refunds-empty">
    <flux:heading size="sm">{{ __('messages.refunds_empty_title') }}</flux:heading>
    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('messages.refunds_empty_hint') }}</flux:text>
    @if (is_string($ordersHref) && $ordersHref !== '')
        <flux:button as="a" href="{{ $ordersHref }}" wire:navigate variant="primary" size="sm" class="!bg-accent !text-accent-foreground hover:!bg-accent-hover">
            {{ __('messages.orders') }}
        </flux:button>
    @endif
</div>
