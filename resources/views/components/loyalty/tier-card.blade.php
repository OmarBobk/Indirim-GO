@props([
    'currentTierName' => 'bronze',
    'discountPercent' => 0.0,
    'rollingSpend' => 0.0,
    'nextTierName' => null,
    'nextTierMinSpend' => null,
    'amountToNext' => null,
    'progressPercent' => null,
    'windowDays' => 90,
    'layout' => 'full', // 'full' | 'compact' (avoid name 'variant' — clashes with Flux icon)
])

@php
    $money = \App\Support\FrontendMoney::for(auth()->user());
    $currentTierKey = strtolower($currentTierName ?? 'bronze');
    $currentTierLabel = \Illuminate\Support\Facades\Lang::has("messages.loyalty_tier_{$currentTierKey}") ? __("messages.loyalty_tier_{$currentTierKey}") : ucfirst($currentTierKey);
    $nextTierKey = $nextTierName ? strtolower($nextTierName) : null;
    $nextTierLabel = $nextTierKey && \Illuminate\Support\Facades\Lang::has("messages.loyalty_tier_{$nextTierKey}") ? __("messages.loyalty_tier_{$nextTierKey}") : ($nextTierName ? ucfirst($nextTierName) : null);
    $hasNextTier = $nextTierName !== null && $progressPercent !== null && $amountToNext !== null;
@endphp

<div
    {{ $attributes->class(['storefront-card storefront-card--pad-md bg-gradient-to-br from-violet-50/80 to-amber-50/60 dark:from-violet-950/30 dark:to-amber-950/20']) }}
>
    @if ($layout === 'full')
        <div class="flex items-center gap-3">
            <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-900/50 dark:text-violet-400">
                <flux:icon icon="sparkles" variant="outline" class="size-6" />
            </div>
            <div>
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">{{ __('messages.loyalty_tier') }}</flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('messages.loyalty_rolling_spend', ['days' => $windowDays]) }}</flux:text>
            </div>
        </div>
    @endif
    <div class="{{ $layout === 'compact' ? '' : 'mt-4' }} flex flex-wrap items-center gap-3">
        <flux:badge class="capitalize font-semibold" color="zinc">{{ $currentTierLabel }}</flux:badge>
        @if ($layout === 'full')
            @if(\App\Models\WebsiteSetting::getPricesVisible())
            <span class="text-lg font-bold tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $money->format($rollingSpend, 'USD', 2) }}</span>
            @else
            <span class="text-lg font-bold tabular-nums text-zinc-500 dark:text-zinc-400">—</span>
            @endif
            @if ($discountPercent > 0)
                <flux:text class="text-sm text-emerald-600 dark:text-emerald-400">{{ number_format($discountPercent, 0) }}% {{ __('messages.discount_percent') }}</flux:text>
            @endif
        @else
            @if(\App\Models\WebsiteSetting::getPricesVisible())
            <span class="tabular-nums text-zinc-900 dark:text-zinc-100" dir="ltr">{{ $money->format($rollingSpend, 'USD', 2) }}</span>
            @else
            <span class="tabular-nums text-zinc-500 dark:text-zinc-400">—</span>
            @endif
        @endif
    </div>
    @if ($hasNextTier)
        <div class="{{ $layout === 'compact' ? 'mt-2' : 'mt-4' }}">
            <div class="mb-1 flex justify-between text-xs text-zinc-500 dark:text-zinc-400">
                <span>{{ __('messages.loyalty_progress_to', ['tier' => $nextTierLabel]) }}</span>
                <span dir="ltr">{{ number_format($progressPercent, 0) }}%</span>
            </div>
            <div class="h-2.5 w-full overflow-hidden rounded-full bg-zinc-200 dark:bg-zinc-700" role="progressbar" aria-valuenow="{{ (int) min(100, $progressPercent) }}" aria-valuemin="0" aria-valuemax="100">
                <div class="h-full rounded-full bg-violet-500 dark:bg-violet-500 transition-all duration-300" style="width: {{ min(100, $progressPercent) }}%"></div>
            </div>
            <flux:text class="mt-2 text-sm text-zinc-600 dark:text-zinc-400">
                @if(\App\Models\WebsiteSetting::getPricesVisible())
                {{ __('messages.loyalty_next_tier', ['tier' => $nextTierLabel, 'amount' => $money->format($amountToNext, 'USD', 2)]) }}
                @else
                {{ __('messages.loyalty_progress_to', ['tier' => $nextTierLabel]) }}
                @endif
            </flux:text>
        </div>
    @else
        <flux:text class="{{ $layout === 'compact' ? 'mt-2' : 'mt-3' }} text-sm font-medium text-zinc-700 dark:text-zinc-300">
            {{ __('messages.loyalty_you_reached', ['tier' => $currentTierLabel]) }}
        </flux:text>
    @endif
</div>
