@props([
    'filter' => 'all',
    'category' => '',
])

@php
    $hasFilters = $filter === 'unread' || $category !== '';

    if ($filter === 'unread' && $category === '') {
        $title = __('messages.activity_empty_unread_title');
        $description = __('messages.activity_empty_unread_description');
        $icon = 'check-circle';
        $variant = 'unread';
    } elseif ($filter === 'action_required') {
        $title = __('messages.activity_empty_action_required_title');
        $description = $category !== ''
            ? match ($category) {
                'orders' => __('messages.activity_empty_category_orders'),
                'money' => __('messages.activity_empty_category_money'),
                'rewards' => __('messages.activity_empty_category_rewards'),
                'account' => __('messages.activity_empty_category_account'),
                default => __('messages.activity_empty_action_required_description'),
            }
            : __('messages.activity_empty_action_required_description');
        $icon = 'check-circle';
        $variant = 'action_required';
    } elseif ($hasFilters) {
        $title = __('messages.activity_empty_filtered_title');
        $description = match ($category) {
            'orders' => __('messages.activity_empty_category_orders'),
            'money' => __('messages.activity_empty_category_money'),
            'rewards' => __('messages.activity_empty_category_rewards'),
            'account' => __('messages.activity_empty_category_account'),
            default => __('messages.activity_empty_filtered_description'),
        };
        $icon = 'funnel';
        $variant = 'filtered';
    } else {
        $title = __('messages.activity_empty_title');
        $description = __('messages.activity_empty_description');
        $icon = 'bell';
        $variant = 'all';
    }
@endphp

<x-storefront.empty
    :icon="$icon"
    :title="$title"
    :description="$description"
    data-test="activity-empty"
    data-empty="{{ $variant }}"
>
    <x-slot:actions>
        @if ($variant === 'filtered' || $variant === 'unread' || $variant === 'action_required')
            <flux:button
                variant="primary"
                wire:click="clearFilters"
                class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                data-test="activity-empty-clear"
            >
                {{ $variant === 'unread' || $variant === 'action_required' ? __('messages.activity_view_all') : __('messages.activity_clear_filters') }}
            </flux:button>
            <flux:button
                variant="ghost"
                href="{{ route('home') }}"
                wire:navigate
                data-test="activity-empty-home"
            >
                {{ __('messages.notifications_continue_shopping') }}
            </flux:button>
        @else
            <flux:button
                variant="primary"
                href="{{ route('home') }}"
                wire:navigate
                class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                data-test="activity-empty-home"
            >
                {{ __('messages.notifications_continue_shopping') }}
            </flux:button>
            <flux:button
                variant="ghost"
                href="{{ route('orders.index') }}"
                wire:navigate
                data-test="activity-empty-orders"
            >
                {{ __('main.my_orders') }}
            </flux:button>
        @endif
    </x-slot:actions>
</x-storefront.empty>
