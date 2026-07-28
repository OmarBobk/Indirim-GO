@props([
    /** @var array<string, mixed> $item */
    'item',
])

@php
    $importance = (string) ($item['importance'] ?? '');
    $isUrgent = $importance === 'urgent';
    $href = $item['href'] ?? null;
    $actionLabel = $item['action_label'] ?? null;
    $hasCta = is_string($href) && $href !== '' && is_string($actionLabel) && $actionLabel !== '';
    $accentClass = $isUrgent
        ? 'border-red-200/80 bg-red-50/40 dark:border-red-900/60 dark:bg-red-950/20'
        : 'border-amber-200/80 bg-amber-50/40 dark:border-amber-900/50 dark:bg-amber-950/20';
    $railClass = $isUrgent
        ? 'bg-red-500 dark:bg-red-400'
        : 'bg-amber-500 dark:bg-amber-400';
@endphp

<x-storefront.card
    padding="sm"
    {{ $attributes->class([$accentClass]) }}
    data-test="home-operational-item"
    data-importance="{{ $importance }}"
    data-activity-id="{{ $item['stable_key'] ?? '' }}"
>
    <div class="flex items-start gap-3">
        <span
            class="mt-1 h-9 w-1 shrink-0 rounded-full {{ $railClass }}"
            aria-hidden="true"
        ></span>

        <div
            class="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
            aria-hidden="true"
        >
            <flux:icon :icon="$item['icon'] ?? 'exclamation-triangle'" class="size-4" />
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="sm" class="storefront-type-section text-zinc-900 dark:text-zinc-100">
                    {{ $item['title'] ?? '' }}
                </flux:heading>

                @if (! empty($item['importance_label']))
                    <flux:badge
                        :color="$item['badge_color'] ?? ($isUrgent ? 'red' : 'amber')"
                        size="sm"
                        class="shrink-0"
                        data-test="home-operational-importance"
                    >
                        {{ $item['importance_label'] }}
                    </flux:badge>
                @endif
            </div>

            @if (! empty($item['description']))
                <flux:text class="storefront-type-body mt-1 text-zinc-600 dark:text-zinc-400">
                    {{ $item['description'] }}
                </flux:text>
            @endif

            <span class="sr-only">
                {{ __('messages.home_operational_item_unresolved_aria', [
                    'title' => $item['title'] ?? '',
                    'importance' => $item['importance_label'] ?? '',
                ]) }}
            </span>
        </div>

        @if ($hasCta)
            <div class="flex shrink-0 flex-col items-end self-center">
                <flux:button
                    size="sm"
                    :variant="$isUrgent ? 'primary' : 'ghost'"
                    :href="$href"
                    wire:navigate
                    data-test="home-operational-item-cta"
                    :aria-label="__('messages.home_operational_cta_aria', [
                        'action' => $actionLabel,
                        'title' => $item['title'] ?? '',
                    ])"
                >
                    {{ $actionLabel }}
                </flux:button>
            </div>
        @endif
    </div>
</x-storefront.card>
