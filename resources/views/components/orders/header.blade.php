@props([])

<header class="space-y-1" data-test="orders-header" data-section="orders-header">
    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
        {{ __('messages.orders') }}
    </flux:heading>
    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
        {{ __('messages.orders_intro') }}
    </flux:text>
</header>
