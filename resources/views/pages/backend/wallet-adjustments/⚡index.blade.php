<?php

use App\Actions\Wallets\AdjustWallet;
use App\Enums\WalletAdjustmentKind;
use App\Enums\WalletTransactionType;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

new class extends Component
{
    use Toastable;

    public string $search = '';

    public ?int $selectedUserId = null;

    public string $amount = '';

    public string $reason = '';

    public bool $showTransactionSummary = false;

    public bool $confirmAcknowledged = false;

    public string $idempotencyKey = '';

    public ?string $lastSuccessAmount = null;

    public ?string $lastSuccessBalance = null;

    public ?string $lastSuccessCurrency = null;

    public ?int $lastSuccessTransactionId = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('adjust_wallets'), 403);
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->search = '';
        $this->resetFormState();
        $this->clearSuccessSummary();
        $this->resetValidation();
    }

    public function clearSelectedUser(): void
    {
        $this->reset([
            'selectedUserId',
            'amount',
            'reason',
            'showTransactionSummary',
            'confirmAcknowledged',
            'idempotencyKey',
        ]);
        $this->clearSuccessSummary();
        $this->resetValidation();
    }

    public function reviewAdjustment(): void
    {
        $this->validate([
            'selectedUserId' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:500'],
        ], [
            'selectedUserId.required' => __('messages.wallet_adjustment_select_user'),
            'amount.required' => __('messages.wallet_adjustment_amount_invalid'),
            'amount.numeric' => __('messages.wallet_adjustment_amount_invalid'),
            'amount.gt' => __('messages.wallet_adjustment_amount_invalid'),
        ]);

        $this->confirmAcknowledged = false;
        $this->idempotencyKey = (string) Str::uuid();
        $this->showTransactionSummary = true;
        $this->clearSuccessSummary();
    }

    public function cancelReview(): void
    {
        $this->reset(['showTransactionSummary', 'confirmAcknowledged', 'idempotencyKey']);
    }

    public function confirmAdjustment(): void
    {
        abort_unless(auth()->user()?->can('adjust_wallets'), 403);

        $this->validate([
            'selectedUserId' => ['required', 'integer', 'exists:users,id'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'reason' => ['nullable', 'string', 'max:500'],
            'idempotencyKey' => ['required', 'string', 'min:8'],
            'confirmAcknowledged' => ['accepted'],
        ], [
            'confirmAcknowledged.accepted' => __('messages.wallet_adjustment_confirm_required'),
            'idempotencyKey.required' => __('messages.wallet_adjustment_idempotency_required'),
        ]);

        $target = User::query()->findOrFail($this->selectedUserId);

        $result = app(AdjustWallet::class)->handle(
            actor: auth()->user(),
            targetUser: $target,
            amount: (string) $this->amount,
            idempotencyKey: $this->idempotencyKey,
            kind: WalletAdjustmentKind::AdminCredit,
            reason: $this->reason !== '' ? $this->reason : null,
            ipAddress: request()->ip(),
        );

        $currency = $result->transaction->meta['currency']
            ?? $this->selectedWallet?->currency
            ?? config('billing.currency', 'USD');

        $this->lastSuccessAmount = bcadd((string) $result->transaction->amount, '0', 2);
        $this->lastSuccessBalance = $result->newBalance;
        $this->lastSuccessCurrency = (string) $currency;
        $this->lastSuccessTransactionId = (int) $result->transaction->id;

        $this->success(__('messages.wallet_adjustment_success', [
            'amount' => $this->lastSuccessAmount,
            'currency' => $this->lastSuccessCurrency,
            'name' => $target->name,
        ]));

        $this->resetFormState();
        $this->resetValidation();
    }

    public function dismissSuccessSummary(): void
    {
        $this->clearSuccessSummary();
    }

    /**
     * @return Collection<int, User>
     */
    public function getSearchResultsProperty(): Collection
    {
        $term = trim($this->search);

        if ($term === '' || mb_strlen($term) < 2) {
            return collect();
        }

        $like = '%'.$term.'%';
        $digits = preg_replace('/\D+/', '', $term) ?? '';

        return User::query()
            ->select(['id', 'name', 'email', 'username', 'phone'])
            ->where(function ($query) use ($like, $digits): void {
                $query->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like)
                    ->orWhere('username', 'like', $like)
                    ->orWhere('phone', 'like', $like);

                if (strlen($digits) >= 2) {
                    $query->orWhere('phone', 'like', '%'.$digits.'%');
                }
            })
            ->orderBy('name')
            ->limit(8)
            ->get();
    }

    public function getSelectedUserProperty(): ?User
    {
        if ($this->selectedUserId === null) {
            return null;
        }

        return User::query()
            ->select(['id', 'name', 'email', 'username', 'phone'])
            ->find($this->selectedUserId);
    }

    public function getSelectedWalletProperty(): ?Wallet
    {
        $user = $this->selectedUser;

        if ($user === null) {
            return null;
        }

        return Wallet::forUser($user);
    }

    public function getNormalizedAmountProperty(): string
    {
        $amount = trim($this->amount);

        if ($amount === '' || ! is_numeric($amount) || bccomp($amount, '0', 2) !== 1) {
            return '0.00';
        }

        return bcadd($amount, '0', 2);
    }

    public function getResultingBalanceProperty(): ?string
    {
        $wallet = $this->selectedWallet;

        if ($wallet === null) {
            return null;
        }

        return bcadd((string) $wallet->balance, $this->normalizedAmount, 2);
    }

    /**
     * @return Collection<int, WalletTransaction>
     */
    public function getRecentAdjustmentsProperty(): Collection
    {
        return WalletTransaction::query()
            ->where('type', WalletTransactionType::Adjustment)
            ->where('status', WalletTransaction::STATUS_POSTED)
            ->with(['wallet.user:id,name,email,phone'])
            ->latest('id')
            ->limit(10)
            ->get();
    }

    public function getHasSuccessSummaryProperty(): bool
    {
        return $this->lastSuccessTransactionId !== null;
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.wallet_adjustments'));
    }

    private function resetFormState(): void
    {
        $this->reset([
            'amount',
            'reason',
            'showTransactionSummary',
            'confirmAcknowledged',
            'idempotencyKey',
        ]);
    }

    private function clearSuccessSummary(): void
    {
        $this->reset([
            'lastSuccessAmount',
            'lastSuccessBalance',
            'lastSuccessCurrency',
            'lastSuccessTransactionId',
        ]);
    }
};
?>

@php
    $symbol = config('billing.currency_symbol', '$');
@endphp

<div class="admin-wallet-adjustments flex h-full w-full flex-1 flex-col gap-10">
    <header class="space-y-2">
        <p class="cf-display text-xs font-semibold tracking-[0.2em] text-[var(--cf-primary)] uppercase">
            {{ __('messages.nav_financials') }}
        </p>
        <flux:heading size="lg" class="cf-display text-3xl tracking-tight text-[var(--cf-foreground)] md:text-4xl">
            {{ __('messages.wallet_adjustments') }}
        </flux:heading>
        <flux:text class="max-w-2xl text-sm leading-relaxed text-[var(--cf-muted-foreground)]">
            {{ __('messages.wallet_adjustments_intro') }}
        </flux:text>
    </header>

    @if ($this->hasSuccessSummary)
        <section
            class="max-w-3xl rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card)] px-5 py-4"
            aria-live="polite"
            wire:key="success-summary-{{ $lastSuccessTransactionId }}"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <p class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.wallet_adjustment_success_summary') }}
                    </p>
                    <flux:heading size="sm" class="text-[var(--cf-foreground)]">
                        {{ __('messages.wallet_adjustment_posted') }}
                    </flux:heading>
                </div>
                <flux:button type="button" size="sm" variant="ghost" wire:click="dismissSuccessSummary">
                    {{ __('messages.dismiss') }}
                </flux:button>
            </div>

            <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.adjustment_amount') }}
                    </dt>
                    <dd class="mt-1 font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                        +{{ $symbol }}{{ number_format((float) $lastSuccessAmount, 2) }}
                        <span class="text-sm font-medium text-[var(--cf-muted-foreground)]">{{ $lastSuccessCurrency }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.new_balance') }}
                    </dt>
                    <dd class="mt-1 font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                        {{ $symbol }}{{ number_format((float) $lastSuccessBalance, 2) }}
                        <span class="text-sm font-medium text-[var(--cf-muted-foreground)]">{{ $lastSuccessCurrency }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.transaction_reference') }}
                    </dt>
                    <dd class="mt-1 font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                        #{{ $lastSuccessTransactionId }}
                    </dd>
                </div>
            </dl>
        </section>
    @endif

    <div class="grid max-w-5xl gap-10 lg:grid-cols-[minmax(0,1fr)_minmax(18rem,22rem)] lg:items-start">
        <div class="space-y-8">
            {{-- Customer --}}
            <section class="space-y-3" aria-labelledby="wa-section-customer">
                <div class="border-b border-[var(--cf-border)] pb-2">
                    <h2 id="wa-section-customer" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.wallet_adjustment_section_customer') }}
                    </h2>
                </div>

                @if ($this->selectedUser)
                    <div
                        class="flex flex-wrap items-start justify-between gap-3"
                        wire:key="selected-user-{{ $this->selectedUser->id }}"
                    >
                        <div class="min-w-0 space-y-1">
                            <p class="truncate text-lg font-semibold text-[var(--cf-foreground)]">
                                {{ $this->selectedUser->name }}
                            </p>
                            <p class="truncate text-sm text-[var(--cf-muted-foreground)]">
                                {{ $this->selectedUser->email }}
                            </p>
                            @if ($this->selectedUser->phone)
                                <p class="text-sm tabular-nums text-[var(--cf-muted-foreground)]" dir="ltr">
                                    {{ $this->selectedUser->phone }}
                                </p>
                            @endif
                            @if ($this->selectedUser->username)
                                <p class="text-sm text-[var(--cf-muted-foreground)]">
                                    @{{ $this->selectedUser->username }}
                                </p>
                            @endif
                        </div>
                        @unless ($showTransactionSummary)
                            <flux:button type="button" size="sm" variant="ghost" wire:click="clearSelectedUser">
                                {{ __('messages.change') }}
                            </flux:button>
                        @endunless
                    </div>
                @else
                    <flux:input
                        wire:model.live.debounce.300ms="search"
                        type="search"
                        :placeholder="__('messages.wallet_adjustment_search_placeholder')"
                        icon="magnifying-glass"
                        autocomplete="off"
                    />

                    @if ($this->searchResults->isNotEmpty())
                        <ul
                            class="divide-y divide-[var(--cf-border)] overflow-hidden rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card)]"
                            role="listbox"
                            aria-label="{{ __('messages.wallet_adjustment_search_results') }}"
                        >
                            @foreach ($this->searchResults as $user)
                                <li wire:key="search-user-{{ $user->id }}">
                                    <button
                                        type="button"
                                        class="flex w-full flex-col gap-0.5 px-4 py-3 text-start transition-colors hover:bg-[var(--cf-card-elevated)] focus:bg-[var(--cf-card-elevated)] focus:outline-none"
                                        wire:click="selectUser({{ $user->id }})"
                                        role="option"
                                    >
                                        <span class="font-medium text-[var(--cf-foreground)]">{{ $user->name }}</span>
                                        <span class="text-sm text-[var(--cf-muted-foreground)]">
                                            {{ $user->email }}
                                            @if ($user->phone)
                                                · <span dir="ltr">{{ $user->phone }}</span>
                                            @endif
                                        </span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @elseif (mb_strlen(trim($search)) >= 2)
                        <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                            {{ __('messages.wallet_adjustment_no_users') }}
                        </flux:text>
                    @endif
                @endif

                @error('selectedUserId')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror
            </section>

            @if ($this->selectedUser && $this->selectedWallet)
                @php
                    $wallet = $this->selectedWallet;
                    $currency = $wallet->currency;
                @endphp

                {{-- Wallet --}}
                <section
                    class="space-y-3"
                    aria-labelledby="wa-section-wallet"
                    wire:key="wallet-section-{{ $wallet->id }}-{{ $wallet->balance }}"
                >
                    <div class="border-b border-[var(--cf-border)] pb-2">
                        <h2 id="wa-section-wallet" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                            {{ __('messages.wallet_adjustment_section_wallet') }}
                        </h2>
                    </div>

                    <dl class="grid gap-4 sm:grid-cols-3">
                        <div>
                            <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.wallet_id') }}
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums text-[var(--cf-foreground)]">#{{ $wallet->id }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.current_balance') }}
                            </dt>
                            <dd class="mt-1 text-xl font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                {{ $symbol }}{{ number_format((float) $wallet->balance, 2) }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.wallet_currency') }}
                            </dt>
                            <dd class="mt-1 text-xl font-semibold text-[var(--cf-foreground)]">{{ $currency }}</dd>
                        </div>
                    </dl>
                </section>

                {{-- Adjustment --}}
                <section
                    class="space-y-4"
                    aria-labelledby="wa-section-adjustment"
                    x-data="{
                        amount: @js($amount),
                        balance: {{ (float) $wallet->balance }},
                        format(value) {
                            return (Math.round(value * 100) / 100).toFixed(2);
                        },
                        get resulting() {
                            const parsed = parseFloat(this.amount);
                            const credit = Number.isFinite(parsed) && parsed > 0 ? parsed : 0;
                            return this.format(this.balance + credit);
                        }
                    }"
                >
                    <div class="border-b border-[var(--cf-border)] pb-2">
                        <h2 id="wa-section-adjustment" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                            {{ __('messages.wallet_adjustment_section_adjustment') }}
                        </h2>
                    </div>

                    @unless ($showTransactionSummary)
                        <flux:field>
                            <flux:label>{{ __('messages.adjustment_amount') }}</flux:label>
                            <flux:input
                                type="number"
                                step="0.01"
                                min="0.01"
                                inputmode="decimal"
                                wire:model.blur="amount"
                                x-model="amount"
                                placeholder="0.00"
                                dir="ltr"
                            />
                            <flux:error name="amount" />
                            <flux:description>
                                {{ __('messages.wallet_adjustment_amount_hint', ['currency' => $currency]) }}
                            </flux:description>
                        </flux:field>

                        <flux:field>
                            <flux:label>{{ __('messages.reason') }} ({{ __('messages.optional') }})</flux:label>
                            <flux:textarea
                                wire:model.blur="reason"
                                rows="3"
                                :placeholder="__('messages.wallet_adjustment_reason_placeholder')"
                            />
                            <flux:error name="reason" />
                        </flux:field>

                        {{-- Result preview --}}
                        <div class="space-y-3 border-t border-[var(--cf-border)] pt-4" aria-labelledby="wa-section-result">
                            <h2 id="wa-section-result" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.wallet_adjustment_section_result') }}
                            </h2>
<div>
                                <p class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.resulting_balance') }}
                                    <span class="font-normal normal-case">({{ __('messages.preview') }})</span>
                                </p>
                                <p class="mt-1 text-2xl font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                    {{ $symbol }}<span x-text="resulting">{{ $this->resultingBalance }}</span>
                                    <span class="ms-1 text-sm font-medium text-[var(--cf-muted-foreground)]">{{ $currency }}</span>
                                </p>
                                <p class="mt-1 text-xs text-[var(--cf-muted-foreground)]">
                                    {{ __('messages.wallet_adjustment_preview_hint') }}
                                </p>
                            </div>

                            <flux:button
                                type="button"
                                variant="primary"
                                wire:click="reviewAdjustment"
                                wire:loading.attr="disabled"
                                wire:target="reviewAdjustment"
                            >
                                {{ __('messages.review_adjustment') }}
                            </flux:button>
                        </div>
                    @endunless
                </section>

                {{-- Transaction summary (inline confirm) --}}
                @if ($showTransactionSummary)
                    <section
                        class="space-y-5 rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card)] px-5 py-5"
                        aria-labelledby="wa-section-summary"
                    >
                        <div class="space-y-1">
                            <h2 id="wa-section-summary" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.transaction_summary') }}
                            </h2>
                            <flux:heading size="sm" class="text-[var(--cf-foreground)]">
                                {{ __('messages.confirm_wallet_adjustment') }}
                            </flux:heading>
                            <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                                {{ __('messages.confirm_wallet_adjustment_hint', ['name' => $this->selectedUser->name]) }}
                            </flux:text>
                        </div>

                        <dl class="divide-y divide-[var(--cf-border)] border-y border-[var(--cf-border)]">
                            <div class="grid gap-1 py-3 sm:grid-cols-[10rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.customer') }}
                                </dt>
                                <dd class="text-sm font-medium text-[var(--cf-foreground)]">
                                    {{ $this->selectedUser->name }}
                                    <span class="block text-[var(--cf-muted-foreground)]">{{ $this->selectedUser->email }}</span>
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[10rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.wallet') }}
                                </dt>
                                <dd class="text-sm font-medium tabular-nums text-[var(--cf-foreground)]">
                                    #{{ $wallet->id }} · {{ $currency }}
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[10rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.current_balance') }}
                                </dt>
                                <dd class="text-sm font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                    {{ $symbol }}{{ number_format((float) $wallet->balance, 2) }} {{ $currency }}
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[10rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.adjustment_amount') }}
                                </dt>
                                <dd class="text-sm font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                    +{{ $symbol }}{{ number_format((float) $this->normalizedAmount, 2) }} {{ $currency }}
                                    <span class="ms-2 text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                        {{ __('messages.wallet_adjustment_kind_admin_credit') }}
                                    </span>
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[10rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.resulting_balance') }}
                                </dt>
                                <dd class="text-base font-bold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                    {{ $symbol }}{{ number_format((float) $this->resultingBalance, 2) }} {{ $currency }}
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[10rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.reason') }}
                                </dt>
                                <dd class="text-sm text-[var(--cf-foreground)]">
                                    {{ filled(trim($reason)) ? $reason : __('messages.wallet_adjustment_no_reason') }}
                                </dd>
                            </div>
                        </dl>

                        <flux:checkbox
                            wire:model="confirmAcknowledged"
                            :label="__('messages.wallet_adjustment_confirm_checkbox')"
                        />
                        @error('confirmAcknowledged')
                            <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                        @enderror

                        <div class="flex flex-wrap gap-2">
                            <flux:button
                                type="button"
                                variant="primary"
                                wire:click="confirmAdjustment"
                                wire:loading.attr="disabled"
                                wire:target="confirmAdjustment"
                            >
                                <span wire:loading.remove wire:target="confirmAdjustment">
                                    {{ __('messages.confirm_adjustment') }}
                                </span>
                                <span wire:loading wire:target="confirmAdjustment">
                                    {{ __('messages.processing') }}
                                </span>
                            </flux:button>
                            <flux:button
                                type="button"
                                variant="ghost"
                                wire:click="cancelReview"
                                wire:loading.attr="disabled"
                            >
                                {{ __('messages.back') }}
                            </flux:button>
                        </div>
                    </section>
                @endif
            @endif
        </div>

        {{-- Recent adjustments --}}
        <aside class="space-y-3 lg:sticky lg:top-4" aria-labelledby="wa-section-recent">
            <div class="border-b border-[var(--cf-border)] pb-2">
                <h2 id="wa-section-recent" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                    {{ __('messages.recent_wallet_adjustments') }}
                </h2>
            </div>

            @if ($this->recentAdjustments->isEmpty())
                <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                    {{ __('messages.recent_wallet_adjustments_empty') }}
                </flux:text>
            @else
                <div class="overflow-hidden rounded-xl border border-[var(--cf-border)] bg-[var(--cf-card)]">
                    <table class="min-w-full text-sm">
                        <thead class="border-b border-[var(--cf-border)] text-xs tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                            <tr>
                                <th class="px-3 py-2 text-start font-semibold">{{ __('messages.customer') }}</th>
                                <th class="px-3 py-2 text-end font-semibold">{{ __('messages.amount') }}</th>
                                <th class="px-3 py-2 text-end font-semibold">#</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--cf-border)]">
                            @foreach ($this->recentAdjustments as $tx)
                                @php
                                    $txCurrency = data_get($tx->meta, 'currency', $tx->wallet?->currency ?? config('billing.currency', 'USD'));
                                    $txUser = $tx->wallet?->user;
                                @endphp
                                <tr wire:key="recent-adj-{{ $tx->id }}">
                                    <td class="px-3 py-2.5">
                                        <div class="font-medium text-[var(--cf-foreground)]">
                                            {{ $txUser?->name ?? __('messages.unknown') }}
                                        </div>
                                        <div class="text-xs text-[var(--cf-muted-foreground)]">
                                            {{ $tx->created_at?->diffForHumans() }}
                                        </div>
                                    </td>
                                    <td class="px-3 py-2.5 text-end font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                        +{{ number_format((float) $tx->amount, 2) }}
                                        <span class="block text-xs font-medium text-[var(--cf-muted-foreground)]">{{ $txCurrency }}</span>
                                    </td>
                                    <td class="px-3 py-2.5 text-end tabular-nums text-[var(--cf-muted-foreground)]">
                                        {{ $tx->id }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </aside>
    </div>
</div>
