@props([
    'orderNumber',
    'formattedDate',
])

<div class="flex flex-wrap items-center justify-between gap-4">
    <div class="space-y-1">
        <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
            {{ __('messages.order_details') }}
        </flux:heading>
        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ $orderNumber }}
        </flux:text>
    </div>
    <div class="text-sm text-zinc-600 dark:text-zinc-400">
        {{ $formattedDate }}
    </div>
</div>
