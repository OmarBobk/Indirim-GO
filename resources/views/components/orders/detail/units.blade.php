@props([
    /** @var list<array<string, mixed>> $units */
    'units',
])

<div class="space-y-3">
    @if ($units === [])
        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('messages.delivery_preparing_hint') }}
        </flux:text>
    @else
        @foreach ($units as $unit)
            <x-orders.detail.unit :unit="$unit" />
        @endforeach
    @endif
</div>
