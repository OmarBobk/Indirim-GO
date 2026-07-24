@props([
    'tiers' => [], // array of { name, min_spend, discount_percentage }
    'currentTierName' => 'bronze',
    'rollingSpend' => 0.0,
    'orientation' => 'vertical', // 'vertical' | 'horizontal'
])

@php
    $money = \App\Support\FrontendMoney::for(auth()->user());
    $currentTierName = strtolower($currentTierName ?? 'bronze');
    $tiersCollection = collect($tiers);
    $nextTier = $tiersCollection->first(fn ($t) => (float) ($t['min_spend'] ?? 0) > $rollingSpend);
@endphp

<div
    {{ $attributes->class(['storefront-card storefront-card--pad-md sm:p-5']) }}
>
    <flux:heading size="sm" class="mb-3 text-zinc-900 dark:text-zinc-100">{{ __('messages.loyalty_benefits') }}</flux:heading>
    <div class="{{ $orientation === 'horizontal' ? 'flex flex-wrap gap-2' : 'space-y-2' }}">
        @foreach ($tiers as $tier)
            @php
                $name = strtolower($tier['name'] ?? '');
                $tierLabel = \Illuminate\Support\Facades\Lang::has("messages.loyalty_tier_{$name}") ? __("messages.loyalty_tier_{$name}") : ucfirst($name);
                $minSpend = (float) ($tier['min_spend'] ?? 0);
                $discount = (float) ($tier['discount_percentage'] ?? 0);
                $isCurrent = $name === $currentTierName;
                $isNext = $nextTier && ($tier['name'] ?? '') === ($nextTier['name'] ?? '');
                $remaining = $isNext ? max(0, $minSpend - $rollingSpend) : null;
            @endphp
            <div
                class="flex items-center gap-3 rounded-xl border p-3 {{ $isCurrent ? 'border-violet-400 bg-violet-50/80 dark:border-violet-500 dark:bg-violet-950/30' : 'border-zinc-200 dark:border-zinc-700' }}"
            >
                <flux:badge color="{{ $isCurrent ? 'violet' : 'zinc' }}" class="capitalize shrink-0">{{ $tierLabel }}</flux:badge>
                @if(\App\Models\WebsiteSetting::getPricesVisible())
                <span class="tabular-nums text-sm text-zinc-600 dark:text-zinc-400" dir="ltr">{{ $money->format($minSpend, 'USD', 0) }}+</span>
                @else
                <span class="text-sm text-zinc-500 dark:text-zinc-400">—</span>
                @endif
                <span class="text-sm font-medium text-emerald-600 dark:text-emerald-400">{{ number_format($discount, 0) }}%</span>
                @if ($isCurrent)
                    <flux:text class="ml-auto text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.loyalty_you_reached', ['tier' => $tierLabel]) }}</flux:text>
                @elseif ($remaining !== null && \App\Models\WebsiteSetting::getPricesVisible())
                    <flux:text class="ml-auto text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.loyalty_remaining_spend') }}: {{ $money->format($remaining, 'USD', 2) }}</flux:text>
                @endif
            </div>
        @endforeach
    </div>
</div>
