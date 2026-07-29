@props([
    'timeline' => [],
])

@php
    $timeline = is_array($timeline) ? $timeline : [];
@endphp

@if ($timeline !== [])
    <section
        class="storefront-card storefront-card--pad-md"
        data-test="transaction-timeline"
        aria-labelledby="transaction-timeline-heading"
    >
        <h2 id="transaction-timeline-heading" class="storefront-type-section">
            {{ __('messages.transaction_timeline_heading') }}
        </h2>
        <ol class="mt-4 space-y-3">
            @foreach ($timeline as $step)
                <li wire:key="tx-timeline-{{ $step['key'] ?? $loop->index }}" class="text-sm">
                    <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ $step['label'] ?? '' }}</p>
                    @if (! empty($step['occurred_at_display']))
                        <time class="text-xs text-zinc-500 dark:text-zinc-400" datetime="{{ $step['occurred_at'] ?? '' }}">
                            {{ $step['occurred_at_display'] }}
                        </time>
                    @endif
                </li>
            @endforeach
        </ol>
    </section>
@endif
