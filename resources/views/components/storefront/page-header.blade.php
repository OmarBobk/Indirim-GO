@props([
    'title' => null,
    'description' => null,
    'showBack' => false,
    'backFallback' => null,
])

<header {{ $attributes->class(['storefront-page-header']) }} data-test="storefront-page-header">
    @if ($showBack)
        <div class="storefront-page-header__back">
            <x-back-button :fallback="$backFallback" />
        </div>
    @endif

    <div class="storefront-page-header__row">
        <div class="min-w-0 flex-1">
            @if ($title)
                <flux:heading size="lg" class="storefront-type-title text-zinc-900 dark:text-zinc-100">
                    {{ $title }}
                </flux:heading>
            @endif

            @if ($description)
                <flux:text class="storefront-type-body mt-1 text-zinc-600 dark:text-zinc-400">
                    {{ $description }}
                </flux:text>
            @endif

            @isset($meta)
                <div class="mt-2">
                    {{ $meta }}
                </div>
            @endisset
        </div>

        @isset($actions)
            <div class="storefront-page-header__actions">
                {{ $actions }}
            </div>
        @endisset
    </div>
</header>
