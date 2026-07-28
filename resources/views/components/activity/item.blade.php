@props([
    /** @var array<string, mixed> $item */
    'item',
])

@php
    $isUnread = (bool) ($item['is_unread'] ?? false);
    $notificationId = $item['notification_id'] ?? null;
    $href = $item['href'] ?? null;
    $actionLabel = $item['action_label'] ?? null;
    $money = $item['money'] ?? null;
    $hasCta = is_string($href) && $href !== '' && is_string($actionLabel) && $actionLabel !== '';
@endphp

<x-storefront.card
    padding="sm"
    {{ $attributes->class([
        'border-sky-200/80 bg-sky-50/40 dark:border-sky-900 dark:bg-sky-950/20' => $isUnread,
    ]) }}
    data-test="activity-item"
    data-activity-id="{{ $item['stable_key'] ?? '' }}"
    data-unread="{{ $isUnread ? 'true' : 'false' }}"
>
    <div class="flex items-start gap-3">
        <div
            class="mt-0.5 flex size-10 shrink-0 items-center justify-center rounded-full bg-zinc-100 text-zinc-600 dark:bg-zinc-800 dark:text-zinc-300"
            aria-hidden="true"
        >
            <flux:icon :icon="$item['icon'] ?? 'bell'" class="size-5" />
        </div>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <flux:heading size="sm" class="storefront-type-section text-zinc-900 dark:text-zinc-100">
                    {{ $item['title'] ?? '' }}
                </flux:heading>

                @if ($isUnread)
                    <span
                        class="inline-flex items-center gap-1.5 rounded-full bg-sky-100 px-2 py-0.5 text-[11px] font-semibold uppercase tracking-wide text-sky-800 dark:bg-sky-950 dark:text-sky-200"
                        data-test="activity-item-unread"
                    >
                        <span class="size-1.5 rounded-full bg-sky-500" aria-hidden="true"></span>
                        <span>{{ __('messages.unread') }}</span>
                    </span>
                @endif

                @if (($item['show_status_badge'] ?? false) && ! empty($item['status_token']))
                    <flux:badge :color="$item['badge_color'] ?? 'zinc'" size="sm" class="shrink-0">
                        {{ $item['importance_label'] ?? $item['status_token'] }}
                    </flux:badge>
                @endif
            </div>

            @if (! empty($item['description']))
                <flux:text class="storefront-type-body mt-1 text-zinc-600 dark:text-zinc-400">
                    {{ $item['description'] }}
                </flux:text>
            @endif

            <div class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1">
                <flux:text class="storefront-type-meta">
                    <time datetime="{{ $item['occurred_at'] ?? '' }}">
                        {{ $item['occurred_at_display'] ?? '' }}
                    </time>
                </flux:text>

                @if (! empty($item['category_label']))
                    <flux:text class="storefront-type-meta">
                        {{ $item['category_label'] }}
                    </flux:text>
                @endif

                @if (is_array($money) && ($money['visible'] ?? false))
                    <span
                        class="storefront-type-meta font-medium text-zinc-800 dark:text-zinc-200"
                        dir="{{ $money['dir'] ?? 'ltr' }}"
                        data-test="activity-item-money"
                    >
                        {{ $money['formatted'] ?? '' }}
                    </span>
                @endif
            </div>
        </div>

        <div class="flex shrink-0 flex-col items-end gap-1">
            @if ($hasCta && $notificationId)
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="arrow-top-right-on-square"
                    :href="$href"
                    wire:navigate
                    wire:click="markAsRead('{{ $notificationId }}')"
                    data-test="activity-item-cta"
                >
                    {{ $actionLabel }}
                </flux:button>
            @elseif ($hasCta)
                <flux:button
                    size="sm"
                    variant="ghost"
                    icon="arrow-top-right-on-square"
                    :href="$href"
                    wire:navigate
                    data-test="activity-item-cta"
                >
                    {{ $actionLabel }}
                </flux:button>
            @elseif ($notificationId && $isUnread)
                <flux:button
                    size="sm"
                    variant="ghost"
                    wire:click="markAsRead('{{ $notificationId }}')"
                    data-test="activity-item-mark-read"
                    aria-label="{{ __('messages.mark_read') }}"
                >
                    {{ __('messages.mark_read') }}
                </flux:button>
            @endif
        </div>
    </div>
</x-storefront.card>
