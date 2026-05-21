<?php

use App\Models\LoyaltyTierConfig;
use App\Models\User;
use App\Services\LoyaltySpendService;
use App\Support\UserRegistrationSourceResolver;
use Illuminate\View\View;
use Livewire\Component;

new class extends Component
{
    public User $user;

    public string $registrationSummary = '';

    public ?string $lockUntilDate = null;

    public ?string $lockUntilTime = null;

    public function mount(User $user): void
    {
        $this->authorize('view', $user);
        $this->user = $user->load(['loyaltyOverrideBy', 'referredBy:id,username,name']);
        $this->registrationSummary = UserRegistrationSourceResolver::resolveForUser($this->user)->describe($this->user);
        if ($user->loyalty_locked_until !== null) {
            $this->lockUntilDate = $user->loyalty_locked_until->format('Y-m-d');
            $this->lockUntilTime = $user->loyalty_locked_until->format('H:i');
        }
    }

    public function getRollingSpendProperty(): float
    {
        $windowDays = \App\Models\LoyaltySetting::getRollingWindowDays();

        return app(LoyaltySpendService::class)->computeRollingSpend($this->user, $windowDays);
    }

    public function getTierConfigProperty(): ?LoyaltyTierConfig
    {
        $role = $this->user->loyaltyRole();
        if ($role === null) {
            return null;
        }
        $tierName = $this->user->loyalty_tier?->value ?? 'bronze';

        return LoyaltyTierConfig::query()->forRole($role)->where('name', $tierName)->first();
    }

    public function getNextTierProperty(): ?LoyaltyTierConfig
    {
        $role = $this->user->loyaltyRole();
        if ($role === null) {
            return null;
        }
        $spend = $this->rollingSpend;

        return LoyaltyTierConfig::query()
            ->forRole($role)
            ->where('min_spend', '>', $spend)
            ->orderBy('min_spend')
            ->first();
    }

    public function saveLoyaltyLock(): void
    {
        $this->authorize('update', $this->user);
        $lockUntil = null;
        if ($this->lockUntilDate !== null && $this->lockUntilDate !== '') {
            $date = $this->lockUntilTime !== null && $this->lockUntilTime !== ''
                ? $this->lockUntilDate.' '.$this->lockUntilTime.':00'
                : $this->lockUntilDate.' 23:59:59';
            $lockUntil = \Carbon\Carbon::parse($date);
        }
        $previous = $this->user->loyalty_locked_until?->toIso8601String();
        $this->user->update([
            'loyalty_locked_until' => $lockUntil,
            'loyalty_override_by' => $lockUntil !== null ? auth()->id() : null,
        ]);
        activity()
            ->inLog('loyalty')
            ->event('loyalty.override')
            ->performedOn($this->user)
            ->causedBy(auth()->user())
            ->withProperties([
                'user_id' => $this->user->id,
                'lock_until_before' => $previous,
                'lock_until_after' => $lockUntil?->toIso8601String(),
                'admin_id' => auth()->id(),
            ])
            ->log('Loyalty tier lock updated');
        $this->user->refresh();
        $this->dispatch('loyalty-lock-saved');
    }

    public function clearLoyaltyLock(): void
    {
        $this->authorize('update', $this->user);
        $this->user->update([
            'loyalty_locked_until' => null,
            'loyalty_override_by' => null,
        ]);
        activity()
            ->inLog('loyalty')
            ->event('loyalty.override_cleared')
            ->performedOn($this->user)
            ->causedBy(auth()->user())
            ->withProperties(['user_id' => $this->user->id, 'admin_id' => auth()->id()])
            ->log('Loyalty tier lock cleared');
        $this->lockUntilDate = null;
        $this->lockUntilTime = null;
        $this->user->refresh();
        $this->dispatch('loyalty-lock-saved');
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.customer_detail'));
    }
};
?>

@php
    $tierName = strtolower($user->loyalty_tier?->value ?? 'bronze');
    $tierIcon = match ($tierName) {
        'gold' => 'star',
        'silver' => 'sparkles',
        default => 'gift',
    };
    $tierIconWrap = match ($tierName) {
        'gold' => 'bg-amber-200/90 text-amber-900 dark:bg-amber-800/60 dark:text-amber-100',
        'silver' => 'bg-slate-200 text-slate-800 dark:bg-slate-700 dark:text-slate-200',
        default => 'bg-amber-100 text-amber-800 dark:bg-amber-900/45 dark:text-amber-200',
    };
    $currencySymbol = config('billing.currency_symbol', '$');
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-6" data-test="admin-user-show-page">
    {{-- Hero --}}
    <section class="relative overflow-hidden rounded-2xl border border-violet-200/60 bg-gradient-to-br from-violet-500/[0.14] via-fuchsia-500/[0.08] to-cyan-500/[0.12] p-6 shadow-md shadow-violet-500/5 ring-1 ring-violet-500/10 dark:border-violet-500/20 dark:from-violet-600/25 dark:via-fuchsia-600/15 dark:to-cyan-600/20 dark:shadow-fuchsia-900/20 dark:ring-violet-400/15 sm:p-8">
        <div class="pointer-events-none absolute -end-16 -top-16 size-56 rounded-full bg-gradient-to-br from-fuchsia-400/25 to-cyan-400/20 blur-3xl dark:from-fuchsia-500/15 dark:to-cyan-500/15" aria-hidden="true"></div>
        <div class="pointer-events-none absolute -bottom-12 -start-12 size-48 rounded-full bg-gradient-to-tr from-violet-400/20 to-amber-400/15 blur-3xl dark:from-violet-500/10 dark:to-amber-500/10" aria-hidden="true"></div>
        <div class="relative flex flex-col gap-6 lg:flex-row lg:items-start lg:justify-between">
            <div class="flex flex-col gap-4 sm:flex-row sm:items-start">
                <div class="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-gradient-to-br from-violet-500 to-fuchsia-600 text-white shadow-lg shadow-violet-500/30 ring-2 ring-white/40 dark:shadow-fuchsia-900/40 dark:ring-white/10">
                    <flux:icon icon="user" class="size-8" />
                </div>
                <div class="min-w-0 flex-1">
                    <flux:heading size="lg" class="inline-block bg-gradient-to-r from-violet-700 via-fuchsia-600 to-cyan-600 bg-clip-text text-transparent dark:from-violet-300 dark:via-fuchsia-300 dark:to-cyan-300">
                        {{ $user->name }}
                    </flux:heading>
                    <flux:text class="mt-1 max-w-2xl text-zinc-600 dark:text-zinc-300">
                        {{ __('messages.customer_detail_intro') }}
                    </flux:text>
                    <dl class="mt-6 grid gap-4 sm:grid-cols-2">
                        <div class="rounded-xl border border-violet-200/70 border-l-4 border-l-violet-500 bg-gradient-to-r from-violet-50/80 to-white/70 px-4 py-3 shadow-sm dark:border-violet-500/30 dark:from-violet-950/40 dark:to-zinc-900/60 dark:shadow-violet-900/20">
                            <dt class="text-xs font-medium uppercase tracking-wide text-violet-600/90 dark:text-violet-300/90">{{ __('messages.email') }}</dt>
                            <dd class="mt-1 truncate font-medium text-zinc-900 dark:text-zinc-100">{{ $user->email }}</dd>
                        </div>
                        <div class="rounded-xl border border-cyan-200/70 border-l-4 border-l-cyan-500 bg-gradient-to-r from-cyan-50/80 to-white/70 px-4 py-3 shadow-sm dark:border-cyan-500/30 dark:from-cyan-950/35 dark:to-zinc-900/60 dark:shadow-cyan-900/20">
                            <dt class="text-xs font-medium uppercase tracking-wide text-cyan-700 dark:text-cyan-300">{{ __('messages.username') }}</dt>
                            <dd class="mt-1 font-medium text-zinc-900 dark:text-zinc-100">{{ $user->username ?? '—' }}</dd>
                        </div>
                        <div class="sm:col-span-2 rounded-xl border border-fuchsia-200/70 border-l-4 border-l-fuchsia-500 bg-gradient-to-r from-fuchsia-50/80 to-white/70 px-4 py-3 shadow-sm dark:border-fuchsia-500/30 dark:from-fuchsia-950/35 dark:to-zinc-900/60 dark:shadow-fuchsia-900/20">
                            <dt class="text-xs font-medium uppercase tracking-wide text-fuchsia-700 dark:text-fuchsia-300">{{ __('messages.user_registration') }}</dt>
                            <dd class="mt-1 text-sm font-medium leading-snug text-zinc-900 dark:text-zinc-100">{{ $this->registrationSummary }}</dd>
                        </div>
                    </dl>
                </div>
            </div>
            <div class="flex shrink-0 flex-col gap-2 self-start sm:flex-row sm:flex-wrap sm:items-center">
                @can('update', $user)
                    <flux:button
                        type="button"
                        variant="primary"
                        size="sm"
                        icon="pencil"
                        class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                        wire:click="$dispatch('open-edit-modal', { userId: {{ $user->id }} })"
                    >
                        {{ __('messages.edit') }}
                    </flux:button>
                @endcan
                @can('resetPassword', $user)
                    <flux:button
                        type="button"
                        variant="ghost"
                        size="sm"
                        icon="key"
                        class="border border-violet-200/60 bg-white/50 hover:bg-white/90 dark:border-violet-500/25 dark:bg-zinc-900/50 dark:hover:bg-zinc-800"
                        wire:click="$dispatch('open-reset-password-modal', { userId: {{ $user->id }} })"
                    >
                        {{ __('messages.reset_password') }}
                    </flux:button>
                @endcan
                <flux:button
                    variant="ghost"
                    icon="arrow-left"
                    icon-position="left"
                    :href="auth()->user()?->can('manage_users') ? route('admin.users.index') : route('salesperson.users.index')"
                    wire:navigate
                    class="border border-violet-200/60 bg-white/50 hover:bg-white/90 dark:border-violet-500/25 dark:bg-zinc-900/50 dark:hover:bg-zinc-800"
                >
                    {{ __('messages.back') }}
                </flux:button>
            </div>
        </div>
    </section>

    {{-- Loyalty --}}
    <section class="rounded-2xl border border-zinc-200 bg-gradient-to-b from-violet-50/50 via-white to-white p-5 shadow-sm ring-1 ring-violet-500/5 dark:border-zinc-700 dark:from-violet-950/25 dark:via-zinc-900 dark:to-zinc-900 dark:ring-violet-400/10 sm:p-6">
        <div class="mb-6">
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('messages.loyalty_tier') }}</flux:heading>
            <flux:text class="mt-1 block text-sm text-zinc-600 dark:text-zinc-400">{{ __('messages.customer_detail_loyalty_intro') }}</flux:text>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div class="rounded-2xl border border-violet-200/80 border-l-4 border-l-violet-500 bg-gradient-to-br from-violet-50/90 to-zinc-50/50 p-4 shadow-sm dark:border-violet-500/35 dark:from-violet-950/50 dark:to-zinc-800/50">
                <div class="flex items-center gap-3">
                    <div class="flex size-10 shrink-0 items-center justify-center rounded-xl {{ $tierIconWrap }}">
                        <flux:icon icon="{{ $tierIcon }}" class="size-5" />
                    </div>
                    <div class="min-w-0">
                        <div class="text-xs font-medium uppercase tracking-wide text-violet-600 dark:text-violet-300">{{ __('messages.tier') }}</div>
                        <flux:badge class="mt-1 capitalize">{{ ucfirst($user->loyalty_tier?->value ?? 'bronze') }}</flux:badge>
                    </div>
                </div>
            </div>
            <div class="rounded-2xl border border-emerald-200/80 border-l-4 border-l-emerald-500 bg-gradient-to-br from-emerald-50/95 via-teal-50/40 to-transparent p-4 shadow-sm dark:border-emerald-500/35 dark:from-emerald-950/45 dark:via-teal-950/25 dark:to-transparent">
                <div class="text-xs font-medium uppercase tracking-wide text-emerald-700 dark:text-emerald-300">{{ __('messages.loyalty_rolling_spend', ['days' => \App\Models\LoyaltySetting::getRollingWindowDays()]) }}</div>
                <div class="mt-2 inline-block bg-gradient-to-r from-emerald-600 to-teal-600 bg-clip-text text-2xl font-bold tabular-nums text-transparent dark:from-emerald-300 dark:to-teal-300" dir="ltr">{{ $currencySymbol }}{{ number_format($this->rollingSpend, 2) }}</div>
            </div>
            <div class="rounded-2xl border border-rose-200/80 border-l-4 border-l-rose-500 bg-gradient-to-br from-rose-50/90 to-orange-50/30 p-4 shadow-sm dark:border-rose-500/35 dark:from-rose-950/40 dark:to-orange-950/20">
                <div class="text-xs font-medium uppercase tracking-wide text-rose-700 dark:text-rose-300">{{ __('messages.discount_percent') }}</div>
                <div class="mt-2 flex items-baseline gap-1">
                    <span class="inline-block bg-gradient-to-r from-rose-600 to-orange-500 bg-clip-text text-3xl font-bold text-transparent dark:from-rose-300 dark:to-orange-300">{{ $this->tierConfig ? number_format((float) $this->tierConfig->discount_percentage, 1) : '0' }}</span>
                    <span class="text-lg font-semibold text-rose-500 dark:text-rose-400">%</span>
                </div>
            </div>
            <div class="rounded-2xl border border-indigo-200/80 border-l-4 border-l-indigo-500 bg-gradient-to-br from-indigo-50/80 to-violet-50/40 p-4 shadow-sm dark:border-indigo-500/35 dark:from-indigo-950/40 dark:to-violet-950/25">
                <div class="text-xs font-medium uppercase tracking-wide text-indigo-700 dark:text-indigo-300">{{ __('messages.loyalty_evaluated_at') }}</div>
                <div class="mt-2 text-sm font-medium leading-snug text-indigo-950 dark:text-indigo-100">{{ $user->loyalty_evaluated_at?->format('M d, Y H:i') ?? '—' }}</div>
            </div>
        </div>

        @if ($this->nextTier !== null)
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="rounded-2xl border border-sky-300/70 border-l-4 border-l-sky-500 bg-gradient-to-br from-sky-50/90 via-cyan-50/50 to-transparent p-4 shadow-sm dark:border-sky-500/40 dark:from-sky-950/40 dark:via-cyan-950/25 dark:to-transparent">
                    <div class="text-xs font-medium uppercase tracking-wide text-sky-700 dark:text-sky-300">{{ __('messages.loyalty_next_tier_threshold') }}</div>
                    <div class="mt-2 font-semibold text-sky-950 dark:text-sky-100" dir="ltr">{{ $currencySymbol }}{{ number_format((float) $this->nextTier->min_spend, 2) }} <span class="font-normal text-sky-600/80 dark:text-sky-400/80">({{ ucfirst($this->nextTier->name) }})</span></div>
                </div>
                <div class="rounded-2xl border border-cyan-300/70 border-l-4 border-l-cyan-500 bg-gradient-to-br from-cyan-50/90 via-sky-50/40 to-transparent p-4 shadow-sm dark:border-cyan-500/40 dark:from-cyan-950/40 dark:via-sky-950/20 dark:to-transparent">
                    <div class="text-xs font-medium uppercase tracking-wide text-cyan-700 dark:text-cyan-300">{{ __('messages.loyalty_remaining_spend') }}</div>
                    <div class="mt-2 inline-block bg-gradient-to-r from-cyan-600 to-sky-600 bg-clip-text text-2xl font-bold tabular-nums text-transparent dark:from-cyan-300 dark:to-sky-300" dir="ltr">{{ $currencySymbol }}{{ number_format(max(0, (float) $this->nextTier->min_spend - $this->rollingSpend), 2) }}</div>
                </div>
            </div>
        @endif

        @if ($user->isLoyaltyLocked())
            <flux:callout variant="subtle" icon="lock-closed" class="mt-6 border-amber-200/80 bg-gradient-to-r from-amber-50/90 to-orange-50/50 dark:border-amber-700/50 dark:from-amber-950/40 dark:to-orange-950/30">
                <div class="space-y-1">
                    <p class="font-medium text-zinc-900 dark:text-zinc-100">{{ __('messages.loyalty_locked_until', ['date' => $user->loyalty_locked_until?->format('M d, Y H:i')]) }}</p>
                    @if ($user->loyaltyOverrideBy)
                        <p class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('messages.by') }} {{ $user->loyaltyOverrideBy->name }}</p>
                    @endif
                </div>
            </flux:callout>
        @endif

        @can('update', $user)
            <div class="mt-6 rounded-2xl border border-fuchsia-200/70 bg-gradient-to-br from-fuchsia-50/70 via-violet-50/40 to-violet-50/30 p-5 shadow-inner shadow-violet-500/5 dark:border-fuchsia-500/25 dark:from-fuchsia-950/35 dark:via-violet-950/30 dark:to-violet-950/25">
                <flux:heading size="xs" class="mb-1 text-zinc-900 dark:text-zinc-100">{{ __('messages.loyalty_override') }}</flux:heading>
                <flux:text class="mb-4 block text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.loyalty_override_hint') }}</flux:text>
                <form wire:submit="saveLoyaltyLock" class="flex flex-wrap items-end gap-3">
                    <flux:field class="min-w-40">
                        <flux:label>{{ __('messages.lock_until_date') }}</flux:label>
                        <flux:input type="date" wire:model="lockUntilDate" />
                    </flux:field>
                    <flux:field class="min-w-32">
                        <flux:label>{{ __('messages.time') }}</flux:label>
                        <flux:input type="time" wire:model="lockUntilTime" />
                    </flux:field>
                    <flux:button type="submit" variant="primary" size="sm">{{ __('messages.save') }}</flux:button>
                    @if ($user->loyalty_locked_until)
                        <flux:button type="button" variant="ghost" size="sm" wire:click="clearLoyaltyLock">{{ __('messages.clear_lock') }}</flux:button>
                    @endif
                </form>
            </div>
        @endcan
    </section>

    @can('manage_user_prices')
        <section class="rounded-2xl border border-cyan-200/60 bg-gradient-to-b from-cyan-50/40 via-white to-white p-5 shadow-sm ring-1 ring-cyan-500/10 dark:border-cyan-500/25 dark:from-cyan-950/30 dark:via-zinc-900 dark:to-zinc-900 dark:ring-cyan-400/10 sm:p-6">
            <livewire:users.user-product-prices :user="$user" :key="'user-prices-'.$user->id" />
        </section>
    @endcan

    @can('manage_users')
        {{-- Audit & activity --}}
        <section class="rounded-2xl border border-indigo-200/60 bg-gradient-to-br from-indigo-50/35 via-white to-fuchsia-50/25 p-5 shadow-sm ring-1 ring-indigo-500/10 dark:border-indigo-500/20 dark:from-indigo-950/35 dark:via-zinc-900 dark:to-fuchsia-950/20 dark:ring-indigo-400/10 sm:p-6">
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('messages.audit_log') }}</flux:heading>
            <flux:text class="mt-1 block text-sm text-zinc-600 dark:text-zinc-400">{{ __('messages.customer_detail_audit_intro') }}</flux:text>
            <div class="mt-5 flex flex-wrap gap-3">
                <flux:button variant="primary" size="sm" icon="clock" :href="route('admin.users.audit', $user)" wire:navigate class="!bg-accent !text-accent-foreground hover:!bg-accent-hover">
                    {{ __('messages.audit_timeline') }}
                </flux:button>
                <flux:button variant="ghost" size="sm" icon="document-text" :href="route('admin.activities.index')" wire:navigate>
                    {{ __('messages.view_activity_for_user') }}
                </flux:button>
            </div>
            <flux:text class="mt-4 block text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.loyalty_audit_hint') }}</flux:text>
        </section>

        <section class="overflow-hidden rounded-2xl border border-zinc-200 bg-white shadow-sm ring-1 ring-fuchsia-500/5 dark:border-zinc-700 dark:bg-zinc-900 dark:ring-fuchsia-400/10">
            <div class="border-b border-cyan-200/50 bg-gradient-to-r from-cyan-500/[0.08] via-violet-500/[0.08] to-fuchsia-500/[0.1] px-5 py-4 dark:border-cyan-500/20 dark:from-cyan-950/50 dark:via-violet-950/40 dark:to-fuchsia-950/50">
                <flux:heading size="sm" class="inline-block bg-gradient-to-r from-cyan-700 to-fuchsia-700 bg-clip-text text-transparent dark:from-cyan-200 dark:to-fuchsia-200">{{ __('messages.system_events') }}</flux:heading>
                <flux:text class="mt-1 block text-sm text-zinc-600 dark:text-zinc-300">{{ __('messages.customer_detail_timeline_intro') }}</flux:text>
            </div>
            <div class="p-4 sm:p-5">
                <x-timeline :entity="$user" :show-heading="false" class="!rounded-xl !border-0 !bg-transparent !p-0 !shadow-none" />
            </div>
        </section>
    @endcan

    @if (auth()->user()?->can('update', $user) || auth()->user()?->can('resetPassword', $user))
        <livewire:users.user-modals :referred-only="! auth()->user()?->can('manage_users')" :key="'user-modals-'.$user->id" />
    @endif
</div>
