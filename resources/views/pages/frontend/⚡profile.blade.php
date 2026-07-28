<?php

use App\Models\LoyaltySetting;
use App\Models\LoyaltyTierConfig;
use App\Models\Wallet;
use App\Support\FrontendMoney;
use App\Services\LoyaltySpendService;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::frontend')] class extends Component
{
    public function mount(): void
    {
        abort_unless(auth()->check(), 403);
    }

    #[Computed]
    public function customerWallet(): Wallet
    {
        return Wallet::forUser(auth()->user());
    }

    #[Computed]
    public function walletAvailableToSpend(): float
    {
        return (float) $this->customerWallet->availableToSpend();
    }

    #[Computed]
    public function walletOutstandingDebt(): ?float
    {
        $wallet = $this->customerWallet;

        if (! $wallet->isOverdrawn()) {
            return null;
        }

        return (float) $wallet->outstandingDebt();
    }

    #[Computed]
    public function loyaltyCurrentTierConfig(): ?LoyaltyTierConfig
    {
        $user = auth()->user();
        $role = $user?->loyaltyRole();
        if ($role === null) {
            return null;
        }
        $tierName = $user->loyalty_tier?->value ?? 'bronze';

        return LoyaltyTierConfig::query()->forRole($role)->where('name', $tierName)->first();
    }

    #[Computed]
    public function loyaltyRollingSpend(): float
    {
        $windowDays = LoyaltySetting::getRollingWindowDays();

        return app(LoyaltySpendService::class)->computeRollingSpend(auth()->user(), $windowDays);
    }

    #[Computed]
    public function loyaltyNextTier(): ?LoyaltyTierConfig
    {
        $user = auth()->user();
        $role = $user?->loyaltyRole();
        if ($role === null) {
            return null;
        }

        return LoyaltyTierConfig::query()
            ->forRole($role)
            ->where('min_spend', '>', $this->loyaltyRollingSpend)
            ->orderBy('min_spend')
            ->first();
    }

    #[Computed]
    public function loyaltyProgressPercent(): ?float
    {
        $next = $this->loyaltyNextTier;
        if ($next === null) {
            return null;
        }
        $threshold = (float) $next->min_spend;
        if ($threshold <= 0) {
            return 100.0;
        }

        return min(100.0, round(($this->loyaltyRollingSpend / $threshold) * 100, 1));
    }

    #[Computed]
    public function loyaltyAmountToNextTier(): ?float
    {
        $next = $this->loyaltyNextTier;
        if ($next === null) {
            return null;
        }

        return max(0.0, (float) $next->min_spend - $this->loyaltyRollingSpend);
    }

    public function render(): View
    {
        return $this->view()->title(__('main.profile'));
    }
};
?>

@php
    $money = FrontendMoney::for(auth()->user());
@endphp

<x-storefront.page width="work">
    <x-storefront.page-header :show-back="true" :back-fallback="route('account')" />

    <x-storefront.card class="mb-6">
        <div class="flex flex-wrap items-start gap-4 sm:gap-6">
            <div class="shrink-0">
                @php $user = auth()->user(); @endphp
                @if ($user->profile_photo)
                    <img
                        src="{{ \Illuminate\Support\Facades\Storage::url($user->profile_photo) }}"
                        alt=""
                        class="size-20 rounded-full border-2 border-zinc-200 object-cover dark:border-zinc-600 sm:size-24"
                    />
                @else
                    <div class="w-12 h-12 flex size-20 items-center justify-center rounded-full border-2 border-zinc-200 bg-zinc-100 text-xl font-semibold text-zinc-600 dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-300 sm:size-24">
                        {{ $user->initials() }}
                    </div>
                @endif
            </div>
            <div class="min-w-0 flex-1 space-y-1">
                <flux:heading size="lg" class="storefront-type-title text-zinc-900 dark:text-zinc-100">{{ $user->name }}</flux:heading>
                <flux:text class="storefront-type-body text-zinc-600 dark:text-zinc-400">{{ $user->username }}</flux:text>
                <flux:text class="storefront-type-body block text-zinc-600 dark:text-zinc-400">{{ $user->email }}</flux:text>
                @if ($user->phone || $user->country_code)
                    <flux:text class="storefront-type-body block text-zinc-600 dark:text-zinc-400 {{ app()->getLocale() === 'ar' ? 'text-right' : '' }}" dir="ltr">
                        {{ $user->country_code ? $user->country_code . ' ' : '' }}{{ $user->phone ?? '' }}
                    </flux:text>
                @endif
                @if ($user->timezone)
                    <flux:text class="storefront-type-meta block">
                        {{ $user->timezone->displayName() }}
                    </flux:text>
                @endif
                <div class="pt-2">
                    <flux:button
                        variant="primary"
                        size="sm"
                        href="{{ route('profile.edit-information') }}"
                        wire:navigate
                        icon="pencil"
                    >
                        {{ __('messages.edit') }}
                    </flux:button>
                </div>
            </div>
        </div>
    </x-storefront.card>

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <a
            href="{{ route('wallet') }}"
            wire:navigate
            class="storefront-card storefront-card--pad-sm storefront-card--interactive flex items-center gap-4"
        >
            <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-emerald-100 text-emerald-600 dark:bg-emerald-900/50 dark:text-emerald-400">
                <flux:icon icon="wallet" variant="outline" class="size-6" />
            </div>
            <div class="min-w-0">
                <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('main.wallet') }}</flux:heading>
                @if(\App\Models\WebsiteSetting::getPricesVisible())
                    @if ($this->walletOutstandingDebt !== null)
                        <flux:text class="text-sm font-medium text-red-700 dark:text-red-400" data-test="profile-wallet-debt">
                            {{ __('messages.wallet_you_owe') }}:
                            <span dir="ltr">{{ $money->format((float) $this->walletOutstandingDebt, 'USD', 2) }}</span>
                        </flux:text>
                    @else
                        <flux:text
                            @class([
                                'text-sm font-medium',
                                'text-emerald-700 dark:text-emerald-400' => $this->walletAvailableToSpend > 0,
                                'text-zinc-600 dark:text-zinc-400' => $this->walletAvailableToSpend <= 0,
                            ])
                            data-test="profile-wallet-balance"
                        >
                            <span dir="ltr">{{ $money->format((float) $this->customerWallet->balance, 'USD', 2) }}</span>
                        </flux:text>
                    @endif
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400" data-test="profile-wallet-available">
                        {{ __('messages.wallet_available_to_spend') }}:
                        <span dir="ltr">{{ $money->format((float) $this->walletAvailableToSpend, 'USD', 2) }}</span>
                    </flux:text>
                    @if ($this->customerWallet->credit_enabled && $this->customerWallet->credit_status === \App\Enums\CreditFacilityStatus::Active)
                        <flux:text class="text-xs text-zinc-500 dark:text-zinc-400" data-test="profile-wallet-credit-limit">
                            {{ __('messages.wallet_nav_limit', ['amount' => $money->format((float) $this->customerWallet->credit_limit, 'USD', 2)]) }}
                        </flux:text>
                    @endif
                @endif
            </div>
        </a>
        <a
            href="{{ route('orders.index') }}"
            wire:navigate
            class="storefront-card storefront-card--pad-sm storefront-card--interactive flex items-center gap-4"
        >
            <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-blue-100 text-blue-600 dark:bg-blue-900/50 dark:text-blue-400">
                <flux:icon icon="shopping-bag" variant="outline" class="size-6" />
            </div>
            <div class="min-w-0">
                <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('main.my_orders') }}</flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('messages.orders_intro') }}</flux:text>
            </div>
        </a>
        @if (auth()->user()?->loyaltyRole() !== null)
            <a
                href="{{ route('loyalty') }}"
                wire:navigate
                class="storefront-card storefront-card--pad-sm storefront-card--interactive flex items-center gap-4"
            >
                <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-violet-100 text-violet-600 dark:bg-violet-900/50 dark:text-violet-400">
                    <flux:icon icon="sparkles" variant="outline" class="size-6" />
                </div>
                <div class="min-w-0">
                    <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('main.loyalty') }}</flux:heading>
                    @php
    $profileTierKey = strtolower(auth()->user()?->loyalty_tier?->value ?? 'bronze');
    $profileTierLabel = \Illuminate\Support\Facades\Lang::has("messages.loyalty_tier_{$profileTierKey}") ? __("messages.loyalty_tier_{$profileTierKey}") : ucfirst($profileTierKey);
@endphp
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ $profileTierLabel }}</flux:text>
                </div>
            </a>
        @endif
        <a
            href="{{ route('activity.index') }}"
            wire:navigate
            class="storefront-card storefront-card--pad-sm storefront-card--interactive flex items-center gap-4 sm:col-span-2 lg:col-span-1"
        >
            <div class="flex size-12 shrink-0 items-center justify-center rounded-xl bg-amber-100 text-amber-600 dark:bg-amber-900/50 dark:text-amber-400">
                <flux:icon icon="bell" variant="outline" class="size-6" />
            </div>
            <div class="min-w-0">
                <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">{{ __('messages.activity_page_title') }}</flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">{{ __('messages.activity_page_intro') }}</flux:text>
            </div>
        </a>
    </div>

    @if ($this->loyaltyCurrentTierConfig !== null)
        <section class="mt-6">
            <x-loyalty.tier-card
                :current-tier-name="auth()->user()?->loyalty_tier?->value ?? 'bronze'"
                :discount-percent="(float) $this->loyaltyCurrentTierConfig->discount_percentage"
                :rolling-spend="$this->loyaltyRollingSpend"
                :next-tier-name="$this->loyaltyNextTier?->name"
                :next-tier-min-spend="$this->loyaltyNextTier ? (float) $this->loyaltyNextTier->min_spend : null"
                :amount-to-next="$this->loyaltyAmountToNextTier"
                :progress-percent="$this->loyaltyProgressPercent"
                :window-days="LoyaltySetting::getRollingWindowDays()"
                layout="full"
            />
        </section>
    @endif
</x-storefront.page>
