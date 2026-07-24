@props([
    'icon' => 'inbox',
    'title',
    'description' => null,
])

<div {{ $attributes->class(['storefront-empty'])->merge(['data-test' => 'storefront-empty']) }}>
    <div class="storefront-empty__icon" aria-hidden="true">
        <flux:icon :icon="$icon" class="size-8 text-zinc-400 dark:text-zinc-500" />
    </div>
    <div class="storefront-empty__copy">
        <flux:heading size="sm" class="storefront-type-section text-zinc-900 dark:text-zinc-100">
            {{ $title }}
        </flux:heading>
        @if ($description)
            <flux:text class="storefront-type-body text-zinc-600 dark:text-zinc-400">
                {{ $description }}
            </flux:text>
        @endif
    </div>
    @isset($actions)
        <div class="storefront-empty__actions">
            {{ $actions }}
        </div>
    @endisset
</div>
