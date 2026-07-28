@props([
    /** @var list<array<string, mixed>> $items */
    'items' => [],
    'total' => 0,
    'hasMore' => false,
    'viewAllHref' => '',
])

@if ($items !== [])
    <section
        class="storefront-section-stack gap-2.5"
        data-section="customer-home-operational"
        data-test="customer-home-operational"
        data-zone="operational"
        aria-labelledby="home-operational-heading"
        aria-label="{{ __('messages.home_operational_region_aria') }}"
    >
        <div class="flex flex-wrap items-center justify-between gap-2">
            <div class="min-w-0">
                <flux:heading
                    id="home-operational-heading"
                    size="sm"
                    class="storefront-type-section text-zinc-900 dark:text-zinc-100"
                >
                    {{ __('messages.home_operational_title') }}
                </flux:heading>
                <flux:text class="storefront-type-meta mt-0.5">
                    {{ __('messages.home_operational_intro') }}
                </flux:text>
            </div>

            @if ($hasMore && is_string($viewAllHref) && $viewAllHref !== '')
                <flux:button
                    variant="ghost"
                    size="sm"
                    :href="$viewAllHref"
                    wire:navigate
                    data-test="home-operational-view-all"
                >
                    {{ __('messages.home_operational_view_all') }}
                </flux:button>
            @endif
        </div>

        <ul class="flex flex-col gap-2" role="list" data-test="home-operational-list">
            @foreach ($items as $item)
                <li wire:key="home-op-{{ $item['stable_key'] ?? $loop->index }}">
                    <x-home.operational-item :item="$item" />
                </li>
            @endforeach
        </ul>
    </section>
@endif
