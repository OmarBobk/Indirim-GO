@props([
    'unitId',
    /** @var list<array{key: string, label: string, value: string, image_urls: list<string>}> $entries */
    'entries',
])

<div class="min-w-0 space-y-2">
    <flux:text class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
        {{ __('messages.delivery_payload') }}
    </flux:text>
    <div class="grid min-w-0 gap-3 overflow-hidden rounded-xl border border-zinc-200 bg-white p-3 dark:border-zinc-700 dark:bg-zinc-900 sm:p-4">
        @foreach ($entries as $entry)
            <x-orders.detail.payload-field
                :unit-id="$unitId"
                :entry="$entry"
            />
        @endforeach
    </div>
</div>
