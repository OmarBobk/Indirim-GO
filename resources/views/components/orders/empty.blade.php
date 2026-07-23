@props([
    'title' => null,
    'hint' => null,
    'showHomeAction' => true,
])

<div
    class="flex flex-col items-center justify-center gap-4 rounded-2xl border border-dashed border-zinc-200 px-6 py-16 text-center dark:border-zinc-700"
    data-test="orders-empty"
>
    <div class="flex size-16 items-center justify-center rounded-full bg-zinc-100 dark:bg-zinc-800">
        <flux:icon icon="shopping-bag" class="size-8 text-zinc-400 dark:text-zinc-500" />
    </div>
    <div class="space-y-1">
        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
            {{ $title ?? __('messages.no_orders') }}
        </flux:heading>
        <flux:text class="text-zinc-600 dark:text-zinc-400">
            {{ $hint ?? __('messages.no_orders_hint') }}
        </flux:text>
    </div>
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
</div>
