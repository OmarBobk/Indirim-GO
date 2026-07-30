@props([
    'credits' => [],
])

@php
    $credits = is_array($credits) ? $credits : [];
@endphp

@if ($credits !== [])
    <section class="storefront-card storefront-card--pad-md" data-test="earnings-payout-history" aria-labelledby="earnings-history-heading">
        <h2 id="earnings-history-heading" class="storefront-type-section">{{ __('messages.earnings_recent_credits') }}</h2>
        <ul class="mt-3 divide-y divide-zinc-100 dark:divide-zinc-800" role="list">
            @foreach ($credits as $credit)
                <li class="flex items-center justify-between gap-3 py-2 text-sm" wire:key="earn-credit-{{ $loop->index }}">
                    <div>
                        <time class="text-xs text-zinc-500 dark:text-zinc-400" datetime="{{ $credit['credited_at'] ?? '' }}">
                            {{ $credit['credited_at_display'] ?? '—' }}
                        </time>
                        @if (! empty($credit['href']) && ! empty($credit['wallet_transaction_public_ref']))
                            <p class="mt-0.5">
                                <a href="{{ $credit['href'] }}" wire:navigate class="font-mono text-xs text-(--color-accent) hover:underline" dir="ltr">
                                    {{ $credit['wallet_transaction_public_ref'] }}
                                </a>
                            </p>
                        @endif
                    </div>
                    <p class="font-medium tabular-nums" dir="ltr">{{ $credit['amount']['formatted'] ?? '—' }}</p>
                </li>
            @endforeach
        </ul>
    </section>
@endif
