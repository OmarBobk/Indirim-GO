@props([
    /** @var list<array<string, mixed>> $items */
    'items' => [],
    'total' => 0,
    'hasMore' => false,
])

@if ($items !== [])
    <section
        class="storefront-section-stack gap-3"
        data-test="activity-action-required-section"
        aria-labelledby="activity-action-required-heading"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <flux:heading
                    id="activity-action-required-heading"
                    size="sm"
                    class="storefront-type-section text-zinc-900 dark:text-zinc-100"
                >
                    {{ __('messages.activity_action_required_section_title') }}
                </flux:heading>
                <flux:text class="storefront-type-meta mt-0.5">
                    {{ __('messages.activity_action_required_section_intro') }}
                </flux:text>
            </div>

            @if ($hasMore)
                <flux:button
                    variant="ghost"
                    size="sm"
                    wire:click="setFilter('action_required')"
                    data-test="activity-view-all-action-required"
                >
                    {{ __('messages.activity_view_all_action_required') }}
                    <span class="ms-1 tabular-nums">({{ $total }})</span>
                </flux:button>
            @endif
        </div>

        <div class="flex flex-col gap-3">
            @foreach ($items as $item)
                <x-activity.item
                    :item="$item"
                    wire:key="activity-action-{{ $item['stable_key'] }}"
                />
            @endforeach
        </div>
    </section>
@endif
