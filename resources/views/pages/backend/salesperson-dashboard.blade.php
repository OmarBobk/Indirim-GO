<div class="dashboard-shell mx-auto flex w-full max-w-[1600px] flex-col gap-6 px-3 py-4 sm:px-4 lg:px-6">
    @if ($this->canSelectSalespersonDashboard() && $this->selectableSalespeople->count() > 1)
        <section
            class="glass-card flex flex-col gap-3 rounded-2xl border border-white/10 bg-[hsl(var(--surface-1)/0.85)] p-4 sm:flex-row sm:items-end sm:justify-between sm:gap-4"
            wire:key="salesperson-dashboard-view-as"
        >
            <div class="min-w-0 space-y-1">
                <p class="text-xs font-semibold uppercase tracking-wide text-[hsl(var(--foreground)/0.55)]">
                    {{ __('messages.salesperson_dashboard_view_as') }}
                </p>
                <p class="text-sm text-[hsl(var(--foreground)/0.72)]">
                    {{ __('messages.salesperson_dashboard_view_as_hint') }}
                </p>
            </div>
            <div class="w-full shrink-0 sm:max-w-xs">
                <flux:select wire:model.live="as" class="w-full">
                    <flux:select.option value="">{{ __('messages.salesperson_dashboard_view_as_self') }}</flux:select.option>
                    @foreach ($this->selectableSalespeople as $sp)
                        <flux:select.option value="{{ (string) $sp->id }}">{{ $sp->name }} ({{ $sp->username }})</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
        </section>
    @endif

    <x-dashboard.header
        :referral-link="$dashboardReferralLink"
        :display-name="$dashboardWelcomeName"
    />

    <x-dashboard.kpi-hero
        :kpis="$kpis"
        :chart-series="$chartSeries"
        :chart-presets="$chartPresets"
        active-range="30d"
        :chart-filter="$chartFilter"
        :chart-start-date="$chartStartDate"
        :chart-end-date="$chartEndDate"
        :hero-key="'hero-chart-'.$chartFilter.'-'.($chartStartDate ?? 'null').'-'.($chartEndDate ?? 'null').'-'.count($chartSeries['labels'] ?? [])"
    />

    <section class="grid gap-6 lg:grid-cols-2">
        <x-dashboard.payout-card :payout="$payout" :allow-payout-request="! $this->isViewingAnotherSalesperson()" />
        <x-dashboard.leaderboard :customers="$customers" />
    </section>

    <x-dashboard.referred-users :users="$referredUsers" />

    <x-dashboard.orders-table :orders="$orders" />

    <x-dashboard.earnings-panel
        :summary="$earningsSummary"
        :history="$payoutHistory"
    />
</div>
