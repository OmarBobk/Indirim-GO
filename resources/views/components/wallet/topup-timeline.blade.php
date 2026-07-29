@props(['timeline' => []])

@php $timeline = is_array($timeline) ? $timeline : []; @endphp

@unless ($timeline === [])
    <section class="storefront-card storefront-card--pad-md" data-test="topup-timeline" aria-labelledby="topup-timeline-heading">
        <flux:heading size="sm" id="topup-timeline-heading">{{ __('messages.topup_timeline_heading') }}</flux:heading>
        <ol class="mt-4 space-y-3">
            @foreach ($timeline as $event)
                <li class="flex gap-3" wire:key="timeline-{{ $event['key'] ?? $loop->index }}">
                    <span class="mt-1 size-2 shrink-0 rounded-full bg-(--color-accent)" aria-hidden="true"></span>
                    <div>
                        <p class="text-sm font-medium text-zinc-900 dark:text-zinc-100">{{ $event['label'] ?? '' }}</p>
                        @if (! empty($event['occurred_at_display']))
                            <time class="text-xs text-zinc-500 dark:text-zinc-400" datetime="{{ $event['occurred_at'] ?? '' }}">
                                {{ $event['occurred_at_display'] }}
                            </time>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>
@endunless
