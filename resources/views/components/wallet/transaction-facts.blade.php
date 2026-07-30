@props([
    'detail' => [],
])

@php
    $detail = is_array($detail) ? $detail : [];
    $amount = is_array($detail['amount'] ?? null) ? $detail['amount'] : [];
    $isCredit = (bool) ($amount['is_credit'] ?? false);
    $amountClass = $isCredit
        ? 'text-emerald-700 dark:text-emerald-400'
        : 'text-red-700 dark:text-red-400';
@endphp

<section
    class="storefront-card storefront-card--pad-md"
    data-test="transaction-facts"
    aria-labelledby="transaction-facts-heading"
>
    <h2 id="transaction-facts-heading" class="storefront-type-section">
        {{ $detail['a11y']['facts'] ?? __('messages.transaction_facts_heading') }}
    </h2>

    <dl class="mt-4 grid gap-3 sm:grid-cols-2">
        <div>
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_reference') }}</dt>
            <dd class="mt-0.5 font-mono text-sm text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $detail['public_reference'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_type') }}</dt>
            <dd class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100">{{ $detail['type_label'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_direction') }}</dt>
            <dd class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100">{{ $detail['direction_label'] ?? '—' }}</dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_amount') }}</dt>
            <dd class="mt-0.5 text-sm font-semibold tabular-nums {{ $amountClass }}" dir="ltr">
                {{ $amount['formatted'] ?? '—' }}
                <span class="sr-only">{{ $detail['direction_label'] ?? '' }}</span>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.transaction_posted_on') }}</dt>
            <dd class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100">
                <time datetime="{{ $detail['posted_at'] ?? '' }}">{{ $detail['posted_at_display'] ?? '—' }}</time>
            </dd>
        </div>
        <div>
            <dt class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.status') }}</dt>
            <dd class="mt-0.5 text-sm text-zinc-900 dark:text-zinc-100">{{ $detail['status_label'] ?? '—' }}</dd>
        </div>
    </dl>
</section>
