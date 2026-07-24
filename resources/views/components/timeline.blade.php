@props([
    'events' => collect(),
    'isCustomerAudience' => false,
])

@php
    use App\Enums\SystemEventSeverity;
    use App\Support\CustomerSystemEventPresenter;
@endphp

<div
    class="timeline-entity rounded-xl border border-zinc-200 bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
    data-timeline-audience="{{ $isCustomerAudience ? 'customer' : 'admin' }}"
    {{ $attributes }}
>
    @if ($showHeading)
        <h3 class="mb-3 text-sm font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
            {{ $isCustomerAudience ? __('messages.wallet_account_activity') : __('messages.system_events') }}
        </h3>
    @endif
    @if ($events->isEmpty())
        <p class="text-sm text-zinc-500 dark:text-zinc-400">
            {{ $isCustomerAudience ? __('messages.wallet_account_activity_empty') : __('messages.no_system_events_hint') }}
        </p>
    @else
        <div class="relative">
            <div class="absolute left-3 top-0 h-full w-0.5 bg-zinc-200 dark:bg-zinc-700" aria-hidden="true"></div>
            <ul class="space-y-0">
                @foreach ($events as $event)
                    @php
                        $dotColor = $event->is_financial
                            ? 'bg-emerald-500'
                            : ($event->severity === SystemEventSeverity::Critical ? 'bg-red-500' : 'bg-zinc-400 dark:bg-zinc-500');
                    @endphp
                    <li class="relative flex gap-3 pb-4 pl-8 last:pb-0" wire:key="timeline-event-{{ $event->id }}">
                        <div class="absolute left-2 top-1.5 h-2.5 w-2.5 shrink-0 rounded-full border-2 border-white dark:border-zinc-900 {{ $dotColor }}" aria-hidden="true"></div>
                        <div class="min-w-0 flex-1">
                            @if ($isCustomerAudience)
                                @php
                                    $presented = app(CustomerSystemEventPresenter::class)->present($event, auth()->user());
                                @endphp
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <time class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400" datetime="{{ $event->created_at?->toIso8601String() ?? '' }}">
                                        {{ $event->created_at?->format('M d, H:i') ?? '—' }}
                                    </time>
                                </div>
                                <p class="mt-1 text-sm font-medium text-zinc-900 dark:text-zinc-100" data-test="timeline-event-title">
                                    {{ $presented['title'] }}
                                </p>
                                @if (filled($presented['description']))
                                    <p class="mt-0.5 text-sm text-zinc-600 dark:text-zinc-400" data-test="timeline-event-description">
                                        {{ $presented['description'] }}
                                    </p>
                                @endif
                                @if ($presented['facts'] !== [])
                                    <dl class="mt-2 space-y-1" data-test="timeline-event-facts">
                                        @foreach ($presented['facts'] as $fact)
                                            <div class="flex items-baseline justify-between gap-3 text-sm">
                                                <dt class="text-zinc-500 dark:text-zinc-400">{{ $fact['label'] }}</dt>
                                                <dd
                                                    @class([
                                                        'tabular-nums font-medium',
                                                        'text-red-700 dark:text-red-400' => $fact['tone'] === 'debt',
                                                        'text-emerald-700 dark:text-emerald-400' => $fact['tone'] === 'positive',
                                                        'text-zinc-800 dark:text-zinc-200' => $fact['tone'] === 'neutral',
                                                    ])
                                                    dir="ltr"
                                                >
                                                    {{ $fact['value'] }}
                                                </dd>
                                            </div>
                                        @endforeach
                                    </dl>
                                @endif
                            @else
                                <div class="flex flex-wrap items-center gap-1.5">
                                    <span class="text-xs tabular-nums text-zinc-500 dark:text-zinc-400">
                                        {{ $event->created_at?->format('M d, H:i') ?? '—' }}
                                    </span>
                                    <flux:badge variant="subtle" size="sm" color="zinc">{{ $event->event_type }}</flux:badge>
                                    @if ($event->is_financial)
                                        <flux:badge size="sm" color="emerald">{{ __('messages.financial') }}</flux:badge>
                                    @endif
                                </div>
                                @if ($event->meta && count((array) $event->meta) > 0)
                                    <div class="mt-1.5" x-data="{ expanded: false }">
                                        <button
                                            type="button"
                                            class="text-xs font-medium text-sky-600 hover:text-sky-700 dark:text-sky-400 dark:hover:text-sky-300"
                                            x-on:click="expanded = !expanded"
                                            :aria-expanded="expanded"
                                        >
                                            <span x-text="expanded ? '{{ __('messages.show_less') }}' : '{{ __('messages.view_meta') }}'"></span>
                                        </button>
                                        <div x-show="expanded" x-collapse class="mt-1">
                                            <pre class="max-h-32 overflow-auto rounded border border-zinc-200 bg-zinc-50 p-2 text-xs text-zinc-700 dark:border-zinc-700 dark:bg-zinc-800 dark:text-zinc-200">{{ json_encode($event->meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) }}</pre>
                                        </div>
                                    </div>
                                @endif
                            @endif
                        </div>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
