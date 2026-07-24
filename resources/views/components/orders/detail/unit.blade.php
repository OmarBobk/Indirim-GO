@props([
    /** @var array<string, mixed> $unit */
    'unit',
])

<div class="min-w-0 rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/60" wire:key="fulfillment-unit-{{ $unit['id'] }}">
    @if ($unit['isCompleted'])
        <div class="space-y-3">
            @if ($unit['hasPayload'])
                <x-orders.detail.payload
                    :unit-id="$unit['id']"
                    :entries="$unit['payloadEntries']"
                />
            @else
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ __('messages.no_payload') }}
                </flux:text>
            @endif

            <div class="flex flex-wrap items-center justify-between gap-3 border-t border-zinc-200/80 pt-3 dark:border-zinc-700/80">
                <div>
                    <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                        {{ __('messages.unit') }} {{ $unit['index'] }}
                    </div>
                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                        #{{ $unit['id'] }}
                    </div>
                </div>
                <x-orders.detail.unit-status :unit="$unit" />
            </div>
        </div>

        <x-orders.detail.timeline :events="$unit['timeline'] ?? []" />
    @elseif ($unit['isFailed'])
        <div class="space-y-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.order_detail_failed_delivery') }}
                    </div>
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ __('messages.delivery_failed_contact_support') }}
                    </flux:text>
                </div>
                <x-orders.detail.unit-status :unit="$unit" />
            </div>

            <x-orders.detail.recovery-actions :unit="$unit" />

            <x-orders.detail.timeline :events="$unit['timeline'] ?? []" />

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-zinc-200/70 pt-3 text-xs text-zinc-500 dark:border-zinc-700/70 dark:text-zinc-400">
                <span>{{ __('messages.unit') }} {{ $unit['index'] }}</span>
                <span>#{{ $unit['id'] }}</span>
            </div>
        </div>
    @else
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    {{ __('messages.unit') }} {{ $unit['index'] }}
                </div>
                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                    #{{ $unit['id'] }}
                </div>
            </div>
            <x-orders.detail.unit-status :unit="$unit" />
        </div>

        <div class="mt-3">
            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                {{ __('messages.delivery_preparing_hint') }}
            </flux:text>
        </div>

        <x-orders.detail.timeline :events="$unit['timeline'] ?? []" />
    @endif
</div>
