@props([
    'payout' => [],
    'allowPayoutRequest' => true,
])

@php
    use App\Actions\Commissions\RequestSalespersonPayout;

    $eligibleAmount = (float) ($payout['eligible'] ?? 0);
    $threshold = (float) ($payout['threshold'] ?? 0);
    $pending = (float) ($payout['pending'] ?? 0);
    $daysLeft = (int) ($payout['days_left'] ?? 0);
    $segments = max(1, (int) ($payout['segments'] ?? 8));
    $filled = max(0, min($segments, $segments - $daysLeft));
    $isEligible = $threshold > 0 ? $eligibleAmount >= $threshold : $eligibleAmount > 0;
    $canRequestPayout = $eligibleAmount > RequestSalespersonPayout::MIN_ELIGIBLE_EXCLUSIVE;
    $minExclusiveLabel = '$'.number_format(RequestSalespersonPayout::MIN_ELIGIBLE_EXCLUSIVE, 0);
    $nextDate = \Illuminate\Support\Carbon::parse((string) ($payout['next_date'] ?? now()->toDateString()));
    $lastPaid = (string) ($payout['last_paid'] ?? '—');
@endphp

<article class="glass-card relative overflow-hidden rounded-2xl p-6 sm:p-7">
    <div
        class="pointer-events-none absolute inset-0"
        style="background: radial-gradient(420px 220px at 100% 0%, hsl(var(--accent-pending) / 0.14), transparent 62%);"
    ></div>
    <div class="relative">
            <div class="flex items-start justify-between gap-3">
                <div class="flex min-w-0 items-center gap-2.5">
                <span
                    class="grid size-9 shrink-0 place-items-center rounded-xl ring-1 ring-[hsl(var(--accent-pending)/0.28)]"
                    style="background: hsl(var(--accent-pending) / 0.16);"
                >
                    <flux:icon icon="banknotes" variant="outline" class="size-4 text-[hsl(var(--accent-pending))]" />
                </span>
                    <p class="dashboard-earnings-eyebrow leading-tight">{{ __('messages.dashboard_payout_status') }}</p>
                </div>
                @if ($isEligible)
                    <span
                        class="inline-flex shrink-0 items-center gap-1 rounded-full bg-[hsl(var(--accent-earnings)/0.14)] px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider text-[hsl(var(--accent-earnings))] ring-1 ring-[hsl(var(--accent-earnings)/0.28)]"
                    >
                    <flux:icon icon="shield-check" variant="outline" class="size-3" />
                    {{ __('messages.eligible_for_payout') }}
                </span>
                @else
                    <span
                        class="inline-flex max-w-[11rem] shrink-0 items-center rounded-full bg-[hsl(var(--surface-3)/0.65)] px-2 py-0.5 text-center text-[10px] font-semibold uppercase leading-snug tracking-wider text-zinc-400 ring-1 ring-white/10"
                    >
                    {{ __('messages.not_yet_eligible_for_payout') }}
                </span>
                @endif
            </div>

            <div class="mt-5 flex flex-wrap items-end gap-2">
                <p class="num text-4xl font-semibold leading-none tracking-tight text-[hsl(var(--accent-pending))] sm:text-[2.35rem]">
                    ${{ number_format($eligibleAmount, 2) }}
                </p>
                <p class="pb-1 text-xs text-[hsl(var(--foreground)/0.48)]">
                    {{ __('messages.dashboard_payout_eligible_line') }}
                </p>
            </div>

            <div class="mt-6" x-data="{ total: {{ $segments }}, done: {{ $filled }} }">
                <div class="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-[hsl(var(--foreground)/0.52)]">
                <span class="inline-flex items-center gap-1.5">
                    <flux:icon icon="clock" variant="outline" class="size-3.5 shrink-0 opacity-80" />
                    <span>{{ __('messages.dashboard_payout_next_prefix') }}</span>
                    <span class="font-semibold text-white">{{ $daysLeft }} {{ __('messages.dashboard_payout_days_suffix') }}</span>
                </span>
                    <span class="num tabular-nums text-[hsl(var(--foreground)/0.45)]">{{ $nextDate->format('Y-m-d') }}</span>
                </div>
                <div class="flex gap-1">
                    <template x-for="i in total" :key="i">
                    <span
                        class="h-1.5 min-w-0 flex-1 rounded-full transition-colors"
                        :class="i <= done ? 'bg-[hsl(var(--accent-pending))]' : 'bg-[hsl(var(--surface-3)/0.85)]'"
                        :style="i <= done ? 'box-shadow: 0 0 10px hsl(var(--accent-pending) / 0.55);' : ''"
                    ></span>
                    </template>
                </div>
            </div>

            <div
                class="mt-6 grid grid-cols-3 gap-2 rounded-xl border border-white/[0.06] bg-[hsl(var(--surface-2)/0.55)] p-3 ring-1 ring-white/[0.04] sm:gap-3 sm:p-3.5"
            >
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-[hsl(var(--foreground)/0.45)]">
                        {{ __('messages.dashboard_payout_col_pending') }}
                    </p>
                    <p class="num mt-1 truncate text-sm font-semibold text-white">${{ number_format($pending, 2) }}</p>
                </div>
                <div class="min-w-0">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-[hsl(var(--foreground)/0.45)]">
                        {{ __('messages.dashboard_payout_col_threshold') }}
                    </p>
                    <p class="num mt-1 inline-flex items-center gap-1 truncate text-sm font-semibold text-white">
                        ${{ number_format($threshold, 0) }}
                        @if ($isEligible)
                            <flux:icon icon="check" variant="outline" class="size-3.5 shrink-0 text-[hsl(var(--accent-earnings))]" />
                        @endif
                    </p>
                </div>
                <div class="min-w-0 text-end sm:text-start">
                    <p class="text-[10px] font-semibold uppercase tracking-wider text-[hsl(var(--foreground)/0.45)]">
                        {{ __('messages.dashboard_payout_col_last_paid') }}
                    </p>
                    <p class="num mt-1 text-sm font-semibold text-white tabular-nums">{{ $lastPaid }}</p>
                </div>
            </div>

            @if ($allowPayoutRequest)
                <button
                    type="button"
                    wire:click="requestPayout"
                    wire:loading.attr="disabled"
                    wire:target="requestPayout"
                    @disabled(! $canRequestPayout)
                    title="{{ $canRequestPayout ? '' : __('messages.dashboard_payout_request_disabled_hint', ['min' => $minExclusiveLabel]) }}"
                    class="mt-6 w-full rounded-xl bg-gradient-to-r from-[hsl(var(--accent-earnings))] to-[hsl(var(--accent-customers)/0.82)] px-4 py-2.5 text-sm font-semibold text-[hsl(222_47%_11%)] shadow-[0_10px_28px_-10px_hsl(var(--accent-earnings)/0.55)] transition hover:opacity-[0.96] focus:outline-none focus-visible:ring-2 focus-visible:ring-[hsl(var(--accent-earnings)/0.55)] focus-visible:ring-offset-2 focus-visible:ring-offset-[hsl(var(--surface-1))] disabled:pointer-events-none disabled:cursor-not-allowed disabled:opacity-40 disabled:shadow-none disabled:hover:opacity-40"
                >
                    <span wire:loading.remove wire:target="requestPayout" class="inline-flex items-center justify-center gap-1">
                        {{ __('messages.dashboard_payout_request_cta') }}
                        <span class="opacity-90">·</span>
                        ${{ number_format($eligibleAmount, 2) }}
                    </span>
                    <span wire:loading wire:target="requestPayout" class="inline-flex items-center justify-center gap-2">
                        <flux:icon icon="arrow-path" variant="outline" class="size-4 animate-spin" />
                        {{ __('messages.dashboard_payout_request_sending') }}
                    </span>
                </button>
            @else
                <p class="mt-6 rounded-xl border border-white/10 bg-[hsl(var(--surface-2)/0.45)] px-3 py-2.5 text-center text-xs leading-snug text-[hsl(var(--foreground)/0.55)]">
                    {{ __('messages.salesperson_dashboard_payout_preview_mode') }}
                </p>
            @endif
            <p class="mt-2.5 text-center text-[11px] leading-snug text-[hsl(var(--foreground)/0.48)]">
                {{ __('messages.dashboard_payout_disclaimer') }}
            </p>

    </div>
</article>
