@props([
    /** @var list<array{key: string, label: string, date: string|null, completed: bool, current: bool, tone: string}> $events */
    'events' => [],
])

@if ($events !== [])
    <details
        class="mt-3 rounded-lg border border-zinc-200/80 bg-white/70 dark:border-zinc-700/80 dark:bg-zinc-900/50"
        data-section="order-detail-timeline"
        data-test="order-detail-timeline"
    >
        <summary class="cursor-pointer list-none px-3 py-2 text-xs font-semibold uppercase tracking-wide text-zinc-500 marker:content-none dark:text-zinc-400 [&::-webkit-details-marker]:hidden">
            <span class="inline-flex items-center gap-2">
                <span>{{ __('messages.order_detail_timeline') }}</span>
                <span class="font-normal normal-case tracking-normal text-zinc-400 dark:text-zinc-500">
                    {{ __('messages.order_detail_timeline_toggle') }}
                </span>
            </span>
        </summary>

        <ol class="space-y-0 border-t border-zinc-200/70 px-3 py-3 dark:border-zinc-700/70">
            @foreach ($events as $event)
                @php
                    $dot = match ($event['tone'] ?? 'zinc') {
                        'green' => 'bg-emerald-500',
                        'red' => 'bg-red-500',
                        'blue' => 'bg-blue-500',
                        'amber' => 'bg-amber-400',
                        default => 'bg-zinc-400 dark:bg-zinc-500',
                    };
                @endphp
                <li
                    class="relative flex gap-3 pb-3 pl-6 last:pb-0"
                    wire:key="order-detail-timeline-{{ $event['key'] }}"
                    data-test="order-detail-timeline-event"
                    data-timeline-key="{{ $event['key'] }}"
                    @if ($event['current']) data-timeline-current="1" @endif
                >
                    <span
                        class="absolute start-1 top-1.5 h-2 w-2 rounded-full {{ $dot }} {{ $event['current'] ? 'ring-2 ring-offset-1 ring-zinc-300 dark:ring-offset-zinc-900 dark:ring-zinc-600' : '' }}"
                        aria-hidden="true"
                    ></span>
                    <div class="min-w-0 flex-1">
                        <div @class([
                            'text-sm',
                            'font-semibold text-zinc-900 dark:text-zinc-100' => $event['current'],
                            'font-medium text-zinc-700 dark:text-zinc-300' => $event['completed'],
                            'text-zinc-600 dark:text-zinc-400' => ! $event['current'] && ! $event['completed'],
                        ])>
                            {{ $event['label'] }}
                        </div>
                        @if ($event['date'] !== null && $event['date'] !== '')
                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                {{ $event['date'] }}
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </details>
@endif
