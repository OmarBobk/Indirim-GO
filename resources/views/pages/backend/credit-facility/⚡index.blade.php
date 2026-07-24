<?php

use App\Actions\Wallets\UpdateCreditFacility;
use App\Enums\CreditFacilityStatus;
use App\Enums\WalletType;
use App\Models\User;
use App\Models\Wallet;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;
use Livewire\WithPagination;
use Masmerise\Toaster\Toastable;

new class extends Component
{
    use Toastable;
    use WithPagination;

    public string $search = '';

    public string $opsFilter = 'relevant';

    public int $perPage = 15;

    public ?int $selectedUserId = null;

    public bool $creditEnabled = false;

    public string $creditLimit = '0.00';

    public ?int $paymentTermsDays = null;

    public ?string $creditStatus = null;

    public bool $showReviewSummary = false;

    public bool $confirmAcknowledged = false;

    public ?string $lastSuccessLimit = null;

    public ?string $lastSuccessAvailableCredit = null;

    public ?string $lastSuccessCurrency = null;

    public ?string $lastSuccessCustomerName = null;

    public function mount(): void
    {
        abort_unless(auth()->user()?->can('manage_wallet_credit'), 403);
    }

    public function updatedOpsFilter(): void
    {
        $this->resetPage();
    }

    public function selectUser(int $userId): void
    {
        $this->selectedUserId = $userId;
        $this->search = '';
        $this->showReviewSummary = false;
        $this->confirmAcknowledged = false;
        $this->clearSuccessSummary();
        $this->hydrateFacilityFromWallet();
        $this->resetValidation();
    }

    public function clearSelectedUser(): void
    {
        $this->reset([
            'selectedUserId',
            'creditEnabled',
            'creditLimit',
            'paymentTermsDays',
            'creditStatus',
            'showReviewSummary',
            'confirmAcknowledged',
        ]);
        $this->creditLimit = '0.00';
        $this->creditStatus = null;
        $this->clearSuccessSummary();
        $this->resetValidation();
    }

    public function updatedCreditEnabled(mixed $value): void
    {
        $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN);

        if (! $enabled) {
            // No operational status when the facility is not granted.
            $this->creditStatus = null;
        } elseif ($this->creditStatus === null || $this->creditStatus === '') {
            $this->creditStatus = CreditFacilityStatus::Active->value;
        }
    }

    public function updatedPaymentTermsDays(mixed $value): void
    {
        if ($value === '' || $value === null) {
            $this->paymentTermsDays = null;
        } else {
            $this->paymentTermsDays = (int) $value;
        }
    }

    public function reviewFacility(): void
    {
        $this->validateFacilityInput();

        $this->confirmAcknowledged = false;
        $this->showReviewSummary = true;
        $this->clearSuccessSummary();
    }

    public function cancelReview(): void
    {
        $this->reset(['showReviewSummary', 'confirmAcknowledged']);
    }

    public function confirmFacility(): void
    {
        abort_unless(auth()->user()?->can('manage_wallet_credit'), 403);

        $this->validateFacilityInput();

        $this->validate([
            'confirmAcknowledged' => ['accepted'],
        ], [
            'confirmAcknowledged.accepted' => __('messages.credit_facility_confirm_required'),
        ]);

        $target = User::query()->findOrFail($this->selectedUserId);

        $wallet = app(UpdateCreditFacility::class)->handle(
            actor: auth()->user(),
            targetUser: $target,
            input: [
                'credit_enabled' => $this->creditEnabled,
                'credit_limit' => $this->creditLimit,
                'payment_terms_days' => $this->paymentTermsDays,
                'credit_status' => $this->creditStatus,
            ],
            ipAddress: request()->ip(),
        );

        $this->lastSuccessLimit = bcadd((string) $wallet->credit_limit, '0', 2);
        $this->lastSuccessAvailableCredit = $wallet->availableCredit();
        $this->lastSuccessCurrency = (string) $wallet->currency;
        $this->lastSuccessCustomerName = $target->name;

        $this->success(__('messages.credit_facility_success', [
            'name' => $target->name,
        ]));

        $this->hydrateFacilityFromWallet();
        $this->showReviewSummary = false;
        $this->confirmAcknowledged = false;
        $this->resetValidation();
    }

    public function dismissSuccessSummary(): void
    {
        $this->clearSuccessSummary();
    }

    /**
     * @return LengthAwarePaginator<int, Wallet>
     */
    public function getOpsWalletsProperty(): LengthAwarePaginator
    {
        $query = Wallet::query()
            ->where('type', WalletType::Customer)
            ->with(['user:id,name,email,username,phone']);

        $this->applyOpsFilter($query);

        return $query
            ->orderByRaw('CASE WHEN balance < 0 THEN 0 ELSE 1 END')
            ->orderByDesc('credit_enabled')
            ->orderBy('balance')
            ->orderBy('id')
            ->paginate($this->perPage);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public function getOpsFilterOptionsProperty(): array
    {
        return [
            ['value' => 'relevant', 'label' => __('messages.credit_facility_filter_relevant')],
            ['value' => 'granted', 'label' => __('messages.credit_facility_filter_granted')],
            ['value' => 'active', 'label' => __('messages.credit_facility_filter_active')],
            ['value' => 'suspended', 'label' => __('messages.credit_facility_filter_suspended')],
            ['value' => 'overdrawn', 'label' => __('messages.credit_facility_filter_overdrawn')],
            ['value' => 'not_granted', 'label' => __('messages.credit_facility_filter_not_granted')],
        ];
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

    /**
     * @return list<array{value: int, label: string}>
     */
    public function getPaymentTermsOptionsProperty(): array
    {
        $days = config('billing.wallet_payment_terms_days', [15, 30, 45, 60, 90]);

        return collect($days)
            ->map(fn (int $day): array => [
                'value' => $day,
                'label' => __('messages.credit_facility_net_label', ['days' => $day]),
            ])
            ->values()
            ->all();
    }

    public function getNormalizedCreditLimitProperty(): string
    {
        $limit = trim($this->creditLimit);

        if ($limit === '' || ! is_numeric($limit) || bccomp($limit, '0', 2) === -1) {
            return '0.00';
        }

        return bcadd($limit, '0', 2);
    }

    public function getProjectedAvailableCreditProperty(): ?string
    {
        $wallet = $this->selectedWallet;

        if ($wallet === null) {
            return null;
        }

        $status = $this->creditStatus !== null && $this->creditStatus !== ''
            ? CreditFacilityStatus::tryFrom($this->creditStatus)
            : null;

        return app(UpdateCreditFacility::class)->projectedAvailableCredit(
            wallet: $wallet,
            enabled: $this->creditEnabled,
            creditLimit: $this->normalizedCreditLimit,
            status: $status,
        );
    }

    public function getHasSuccessSummaryProperty(): bool
    {
        return $this->lastSuccessCustomerName !== null;
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.credit_facility'));
    }

    /**
     * @param  Builder<Wallet>  $query
     */
    private function applyOpsFilter(Builder $query): void
    {
        match ($this->opsFilter) {
            'granted' => $query->where('credit_enabled', true),
            'active' => $query
                ->where('credit_enabled', true)
                ->where('credit_status', CreditFacilityStatus::Active->value),
            'suspended' => $query
                ->where('credit_enabled', true)
                ->where('credit_status', CreditFacilityStatus::Suspended->value),
            'overdrawn' => $query->where('balance', '<', 0),
            'not_granted' => $query->where('credit_enabled', false),
            default => $query->where(function (Builder $inner): void {
                $inner->where('credit_enabled', true)
                    ->orWhere('balance', '<', 0);
            }),
        };
    }

    private function validateFacilityInput(): void
    {
        $allowedTerms = array_map('intval', config('billing.wallet_payment_terms_days', [15, 30, 45, 60, 90]));
        $maxLimit = (string) config('billing.wallet_credit_limit_max', '100000.00');

        $this->validate([
            'selectedUserId' => ['required', 'integer', 'exists:users,id'],
            'creditEnabled' => ['required', 'boolean'],
            'creditLimit' => ['required', 'numeric', 'gte:0', 'lte:'.$maxLimit],
            'paymentTermsDays' => [
                $this->creditEnabled ? 'required' : 'nullable',
                'integer',
                'in:'.implode(',', $allowedTerms),
            ],
            'creditStatus' => [
                $this->creditEnabled ? 'required' : 'nullable',
                'in:'.implode(',', CreditFacilityStatus::values()),
            ],
        ], [
            'selectedUserId.required' => __('messages.credit_facility_select_user'),
            'creditLimit.required' => __('messages.credit_facility_limit_invalid'),
            'creditLimit.numeric' => __('messages.credit_facility_limit_invalid'),
            'creditLimit.gte' => __('messages.credit_facility_limit_invalid'),
            'creditLimit.lte' => __('messages.credit_facility_limit_max', ['max' => $maxLimit]),
            'paymentTermsDays.required' => __('messages.credit_facility_terms_required'),
            'paymentTermsDays.in' => __('messages.credit_facility_terms_invalid'),
            'creditStatus.required' => __('messages.credit_facility_status_required_when_enabled'),
            'creditStatus.in' => __('messages.credit_facility_status_invalid'),
        ]);

        $wallet = $this->selectedWallet;

        if ($wallet === null) {
            return;
        }

        $outstandingDebt = $wallet->outstandingDebt();
        $normalizedLimit = $this->normalizedCreditLimit;

        if (
            bccomp($outstandingDebt, '0', 2) === 1
            && bccomp($normalizedLimit, $outstandingDebt, 2) === -1
        ) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'creditLimit' => __('messages.credit_facility_limit_below_debt', [
                    'debt' => $outstandingDebt,
                    'currency' => $wallet->currency,
                ]),
            ]);
        }
    }

    private function hydrateFacilityFromWallet(): void
    {
        $wallet = $this->selectedWallet;

        if ($wallet === null) {
            return;
        }

        $this->creditEnabled = (bool) $wallet->credit_enabled;
        $this->creditLimit = bcadd((string) $wallet->credit_limit, '0', 2);
        $this->paymentTermsDays = $wallet->payment_terms_days;
        $this->creditStatus = $wallet->credit_enabled
            ? ($wallet->credit_status?->value ?? CreditFacilityStatus::Active->value)
            : null;
    }

    private function clearSuccessSummary(): void
    {
        $this->reset([
            'lastSuccessLimit',
            'lastSuccessAvailableCredit',
            'lastSuccessCurrency',
            'lastSuccessCustomerName',
        ]);
    }
};
?>

@php
    $symbol = config('billing.currency_symbol', '$');
    $wallet = $this->selectedWallet;
    $opsWallets = $this->opsWallets;
@endphp

<div class="admin-credit-facility flex h-full w-full flex-1 flex-col gap-8">
    <header class="cf-reveal space-y-2">
        <p class="cf-display text-xs font-semibold tracking-[0.2em] text-[var(--cf-primary)] uppercase">
            {{ __('messages.nav_financials') }}
        </p>
        <flux:heading size="lg" class="cf-display text-pretty text-3xl tracking-tight text-[var(--cf-foreground)] md:text-4xl">
            {{ __('messages.credit_facility') }}
        </flux:heading>
        <flux:text class="max-w-2xl text-sm leading-relaxed text-[var(--cf-muted-foreground)]">
            {{ __('messages.credit_facility_intro') }}
        </flux:text>
    </header>

    @if ($this->hasSuccessSummary)
        <section
            class="cf-reveal cf-reveal-delay-1 cf-success-shell max-w-3xl"
            aria-live="polite"
            wire:key="success-summary-{{ $lastSuccessCustomerName }}-{{ $lastSuccessLimit }}"
        >
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div class="space-y-1">
                    <p class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.credit_facility_success_summary') }}
                    </p>
                    <flux:heading size="sm" class="text-[var(--cf-foreground)]">
                        {{ __('messages.credit_facility_saved') }}
                    </flux:heading>
                </div>
                <flux:button type="button" size="sm" variant="ghost" wire:click="dismissSuccessSummary">
                    {{ __('messages.dismiss') }}
                </flux:button>
            </div>

            <dl class="mt-4 grid gap-4 sm:grid-cols-3">
                <div class="min-w-0">
                    <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.customer') }}
                    </dt>
                    <dd class="mt-1 truncate font-semibold text-[var(--cf-foreground)]">
                        {{ $lastSuccessCustomerName }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.credit_limit') }}
                    </dt>
                    <dd class="mt-1 font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                        {{ $symbol }}{{ number_format((float) $lastSuccessLimit, 2) }}
                        <span class="text-sm font-medium text-[var(--cf-muted-foreground)]">{{ $lastSuccessCurrency }}</span>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.available_credit') }}
                    </dt>
                    <dd class="mt-1 font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                        {{ $symbol }}{{ number_format((float) $lastSuccessAvailableCredit, 2) }}
                        <span class="text-sm font-medium text-[var(--cf-muted-foreground)]">{{ $lastSuccessCurrency }}</span>
                    </dd>
                </div>
            </dl>
        </section>
    @endif

    <div class="cf-reveal cf-reveal-delay-2 grid gap-6 xl:grid-cols-[minmax(0,1.55fr)_minmax(22rem,28rem)] xl:items-start">
        {{-- Ops list --}}
        <section class="cf-table-shell relative min-w-0" aria-labelledby="cf-section-ops" data-test="credit-facility-ops-list">
            <div
                class="pointer-events-none absolute inset-0 z-10 flex items-start justify-center bg-[var(--cf-card)]/70 pt-24 opacity-0 transition-opacity duration-150"
                wire:loading.class="opacity-100"
                wire:target="opsFilter,search,gotoPage,nextPage,previousPage,setPage"
                aria-live="polite"
            >
                <span class="rounded-lg border border-[var(--cf-border)] bg-[var(--cf-card-elevated)] px-3 py-1.5 text-sm text-[var(--cf-muted-foreground)]">
                    {{ __('messages.credit_facility_loading_list') }}
                </span>
            </div>

            <div class="cf-table-head flex flex-col gap-4 px-5 py-4 lg:flex-row lg:items-end lg:justify-between">
                <div class="min-w-0 space-y-1">
                    <h2 id="cf-section-ops" class="cf-display text-base font-semibold text-pretty text-[var(--cf-foreground)]">
                        {{ __('messages.credit_facility_ops_list') }}
                    </h2>
                    <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                        {{ __('messages.credit_facility_ops_hint') }}
                    </flux:text>
                </div>
                <div class="w-full max-w-xs shrink-0">
                    <flux:select wire:model.live="opsFilter" :label="__('messages.filter')" data-test="credit-facility-ops-filter">
                        @foreach ($this->opsFilterOptions as $option)
                            <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                        @endforeach
                    </flux:select>
                </div>
            </div>

            <div class="space-y-3 border-b border-[var(--cf-border)] px-5 py-4">
                <flux:input
                    wire:model.live.debounce.400ms="search"
                    type="search"
                    :label="__('messages.credit_facility_search_hint')"
                    :placeholder="__('messages.wallet_adjustment_search_placeholder')"
                    icon="magnifying-glass"
                    autocomplete="off"
                    name="credit_facility_search"
                    data-test="credit-facility-search"
                />

                @if ($this->searchResults->isNotEmpty())
                    <ul
                        class="divide-y divide-[var(--cf-border)] overflow-hidden rounded-xl border border-[var(--cf-border)] bg-[var(--cf-background)]"
                        role="listbox"
                        aria-label="{{ __('messages.wallet_adjustment_search_results') }}"
                        data-test="credit-facility-search-results"
                    >
                        @foreach ($this->searchResults as $user)
                            <li wire:key="search-user-{{ $user->id }}">
                                <button
                                    type="button"
                                    class="flex w-full flex-col gap-0.5 px-4 py-3 text-start transition-colors duration-150 hover:bg-[var(--cf-card-elevated)] focus-visible:bg-[var(--cf-card-elevated)] focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-[var(--cf-ring)]"
                                    wire:click="selectUser({{ $user->id }})"
                                    role="option"
                                >
                                    <span class="min-w-0 truncate font-medium text-[var(--cf-foreground)]">{{ $user->name }}</span>
                                    <span class="min-w-0 truncate text-sm text-[var(--cf-muted-foreground)]">
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
            </div>

            <div class="overflow-x-auto overscroll-x-contain">
                @if ($opsWallets->isEmpty())
                    <div class="flex flex-col items-center justify-center gap-2 px-6 py-16 text-center" data-test="credit-facility-ops-empty">
                        <flux:heading size="sm" class="cf-display text-pretty text-[var(--cf-foreground)]">
                            @if ($opsFilter === 'relevant')
                                {{ __('messages.credit_facility_empty_relevant') }}
                            @else
                                {{ __('messages.credit_facility_empty_filter') }}
                            @endif
                        </flux:heading>
                        <flux:text class="max-w-md text-sm text-[var(--cf-muted-foreground)]">
                            @if ($opsFilter === 'relevant')
                                {{ __('messages.credit_facility_empty_relevant_hint') }}
                            @else
                                {{ __('messages.credit_facility_empty_filter_hint') }}
                            @endif
                        </flux:text>
                    </div>
                @else
                    <table class="min-w-[56rem] w-full divide-y divide-[var(--cf-border)] text-sm" data-test="credit-facility-ops-table">
                        <thead class="text-xs tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                            <tr>
                                <th scope="col" class="px-5 py-3 text-start font-semibold">{{ __('messages.customer') }}</th>
                                <th scope="col" class="px-5 py-3 text-end font-semibold">{{ __('messages.balance') }}</th>
                                <th scope="col" class="px-5 py-3 text-end font-semibold">{{ __('messages.outstanding_debt') }}</th>
                                <th scope="col" class="px-5 py-3 text-end font-semibold">{{ __('messages.credit_limit') }}</th>
                                <th scope="col" class="px-5 py-3 text-end font-semibold">{{ __('messages.available_credit') }}</th>
                                <th scope="col" class="px-5 py-3 text-start font-semibold">{{ __('messages.payment_terms') }}</th>
                                <th scope="col" class="px-5 py-3 text-start font-semibold">{{ __('messages.credit_facility_granted') }}</th>
                                <th scope="col" class="px-5 py-3 text-start font-semibold">{{ __('messages.credit_status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-[var(--cf-border)]">
                            @foreach ($opsWallets as $opsWallet)
                                @php
                                    $rowUser = $opsWallet->user;
                                    $rowName = $rowUser?->name ?? __('messages.unknown');
                                    $rowDebt = $opsWallet->outstandingDebt();
                                    $rowHasDebt = bccomp($rowDebt, '0', 2) === 1;
                                    $rowSelected = $selectedUserId === $opsWallet->user_id;
                                    $rowBalanceNegative = bccomp((string) $opsWallet->balance, '0', 2) === -1;
                                    $rowStatusEnum = $opsWallet->credit_enabled ? $opsWallet->credit_status : null;
                                    $rowStatusLabel = $opsWallet->credit_enabled
                                        ? ($rowStatusEnum?->label() ?? __('messages.credit_facility_status_active'))
                                        : __('messages.credit_facility_status_none');
                                    $rowStatusChip = match (true) {
                                        ! $opsWallet->credit_enabled => 'cf-status-chip--muted',
                                        $rowStatusEnum === CreditFacilityStatus::Suspended => 'cf-status-chip--warn',
                                        default => 'cf-status-chip--ok',
                                    };
                                @endphp
                                <tr
                                    wire:key="ops-wallet-{{ $opsWallet->id }}"
                                    wire:click="selectUser({{ $opsWallet->user_id }})"
                                    wire:keydown.enter.prevent="selectUser({{ $opsWallet->user_id }})"
                                    wire:keydown.space.prevent="selectUser({{ $opsWallet->user_id }})"
                                    tabindex="0"
                                    role="button"
                                    aria-pressed="{{ $rowSelected ? 'true' : 'false' }}"
                                    aria-label="{{ __('messages.credit_facility_select_row', ['name' => $rowName]) }}"
                                    class="cf-ops-row {{ $rowHasDebt ? 'cf-ops-row--debt' : '' }} {{ $rowSelected ? 'cf-ops-row--selected' : '' }}"
                                    @if ($rowSelected) aria-current="true" @endif
                                    data-test="credit-facility-ops-row-{{ $opsWallet->user_id }}"
                                >
                                    <td class="px-5 py-3">
                                        <div class="min-w-0 max-w-[14rem]">
                                            <div class="truncate font-medium text-[var(--cf-foreground)]">
                                                {{ $rowName }}
                                            </div>
                                            <div class="truncate text-xs text-[var(--cf-muted-foreground)]">
                                                {{ $rowUser?->email ?? '—' }}
                                                @if ($rowUser?->phone)
                                                    · <span dir="ltr">{{ $rowUser->phone }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </td>
                                    <td
                                        class="px-5 py-3 text-end font-semibold tabular-nums {{ $rowBalanceNegative ? 'text-[var(--cf-destructive)]' : 'text-[var(--cf-foreground)]' }}"
                                        dir="ltr"
                                    >
                                        {{ $symbol }}{{ number_format((float) $opsWallet->balance, 2) }}
                                    </td>
                                    <td
                                        class="px-5 py-3 text-end tabular-nums {{ $rowHasDebt ? 'font-bold text-[var(--cf-destructive)]' : 'text-[var(--cf-muted-foreground)]' }}"
                                        dir="ltr"
                                    >
                                        {{ $symbol }}{{ number_format((float) $rowDebt, 2) }}
                                    </td>
                                    <td class="px-5 py-3 text-end tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                        {{ $symbol }}{{ number_format((float) $opsWallet->credit_limit, 2) }}
                                    </td>
                                    <td class="px-5 py-3 text-end tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                        {{ $symbol }}{{ number_format((float) $opsWallet->availableCredit(), 2) }}
                                    </td>
                                    <td class="px-5 py-3 whitespace-nowrap text-[var(--cf-foreground)]">
                                        @if ($opsWallet->payment_terms_days)
                                            {{ __('messages.credit_facility_net_label', ['days' => $opsWallet->payment_terms_days]) }}
                                        @else
                                            <span class="text-[var(--cf-muted-foreground)]">{{ __('messages.credit_facility_terms_none') }}</span>
                                        @endif
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="cf-status-chip {{ $opsWallet->credit_enabled ? 'cf-status-chip--ok' : 'cf-status-chip--muted' }}">
                                            {{ $opsWallet->credit_enabled ? __('messages.yes') : __('messages.no') }}
                                        </span>
                                    </td>
                                    <td class="px-5 py-3">
                                        <span class="cf-status-chip {{ $rowStatusChip }}">
                                            {{ $rowStatusLabel }}
                                        </span>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                @endif
            </div>

            @if ($opsWallets->isNotEmpty())
                <div class="cf-pagination border-t border-[var(--cf-border)] px-5 py-4">
                    {{ $opsWallets->links() }}
                </div>
            @endif
        </section>

        {{-- Detail / edit --}}
        <aside
            class="cf-detail-shell cf-detail-sticky min-w-0 space-y-6"
            aria-labelledby="cf-section-customer"
            data-test="credit-facility-detail"
        >
            <section class="space-y-3">
                <div class="border-b border-[var(--cf-border)] pb-2">
                    <h2 id="cf-section-customer" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                        {{ __('messages.credit_facility_section_customer') }}
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
                        @unless ($showReviewSummary)
                            <flux:button type="button" size="sm" variant="ghost" wire:click="clearSelectedUser">
                                {{ __('messages.change') }}
                            </flux:button>
                        @endunless
                    </div>
                @else
                    <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                        {{ __('messages.credit_facility_select_prompt') }}
                    </flux:text>
                @endif

                @error('selectedUserId')
                    <flux:text class="text-sm text-[var(--cf-destructive)]">{{ $message }}</flux:text>
                @enderror
            </section>

            @if ($this->selectedUser && $wallet)
                @php
                    $currency = $wallet->currency;
                    $isOverdrawn = $wallet->isOverdrawn();
                    $outstandingDebt = $wallet->outstandingDebt();
                    $availableCredit = $wallet->availableCredit();
                    $currentStatus = $wallet->credit_enabled ? $wallet->credit_status : null;
                    $balanceNegative = bccomp((string) $wallet->balance, '0', 2) === -1;
                @endphp

                <section
                    class="space-y-3"
                    aria-labelledby="cf-section-wallet"
                    wire:key="wallet-section-{{ $wallet->id }}-{{ $wallet->balance }}"
                >
                    <div class="border-b border-[var(--cf-border)] pb-2">
                        <h2 id="cf-section-wallet" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                            {{ __('messages.credit_facility_section_wallet') }}
                        </h2>
                    </div>

                    <dl class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.wallet_id') }}
                            </dt>
                            <dd class="mt-1 font-semibold tabular-nums text-[var(--cf-foreground)]">#{{ $wallet->id }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.wallet_currency') }}
                            </dt>
                            <dd class="mt-1 font-semibold text-[var(--cf-foreground)]">{{ $currency }}</dd>
                        </div>
                        <div class="sm:col-span-2">
                            <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.current_balance') }}
                            </dt>
                            <dd
                                class="mt-1 text-2xl font-semibold tabular-nums {{ $balanceNegative ? 'text-[var(--cf-destructive)]' : 'text-[var(--cf-foreground)]' }}"
                                dir="ltr"
                            >
                                {{ $symbol }}{{ number_format((float) $wallet->balance, 2) }}
                            </dd>
                        </div>
                    </dl>

                    @if ($isOverdrawn)
                        <div class="cf-debt-callout" role="status">
                            <p class="text-xs font-semibold tracking-wide text-[var(--cf-destructive)] uppercase">
                                {{ __('messages.outstanding_debt') }}
                            </p>
                            <p class="mt-1 text-2xl font-bold tabular-nums text-[var(--cf-destructive)]" dir="ltr">
                                {{ $symbol }}{{ number_format((float) $outstandingDebt, 2) }}
                                <span class="ms-1 text-sm font-medium">{{ $currency }}</span>
                            </p>
                        </div>
                    @endif
                </section>

                <section
                    class="space-y-4"
                    aria-labelledby="cf-section-facility"
                    wire:key="facility-section-{{ $wallet->id }}"
                >
                    <div class="border-b border-[var(--cf-border)] pb-2">
                        <h2 id="cf-section-facility" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                            {{ __('messages.credit_facility_section_facility') }}
                        </h2>
                        <flux:text class="mt-1 text-sm text-[var(--cf-muted-foreground)]">
                            {{ __('messages.credit_facility_enabled_vs_status_hint') }}
                        </flux:text>
                    </div>

                    <div class="space-y-3">
                        <p class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                            {{ __('messages.credit_facility_current_snapshot') }}
                        </p>
                        <dl class="grid gap-3 sm:grid-cols-2">
                            <div class="rounded-lg border border-[var(--cf-border)] bg-[var(--cf-background)] px-3 py-2.5">
                                <dt class="text-[11px] font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.available_credit') }}
                                </dt>
                                <dd class="mt-1 text-base font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                    {{ $symbol }}{{ number_format((float) $availableCredit, 2) }}
                                </dd>
                            </div>
                            <div class="rounded-lg border border-[var(--cf-border)] bg-[var(--cf-background)] px-3 py-2.5">
                                <dt class="text-[11px] font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.credit_limit') }}
                                </dt>
                                <dd class="mt-1 text-base font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                    {{ $symbol }}{{ number_format((float) $wallet->credit_limit, 2) }}
                                </dd>
                            </div>
                            <div class="rounded-lg border border-[var(--cf-border)] bg-[var(--cf-background)] px-3 py-2.5">
                                <dt class="text-[11px] font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.payment_terms') }}
                                </dt>
                                <dd class="mt-1 text-base font-semibold text-[var(--cf-foreground)]">
                                    @if ($wallet->payment_terms_days)
                                        {{ __('messages.credit_facility_net_label', ['days' => $wallet->payment_terms_days]) }}
                                    @else
                                        {{ __('messages.credit_facility_terms_none') }}
                                    @endif
                                </dd>
                            </div>
                            <div class="rounded-lg border border-[var(--cf-border)] bg-[var(--cf-background)] px-3 py-2.5">
                                <dt class="text-[11px] font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.credit_status') }}
                                </dt>
                                <dd class="mt-1.5">
                                    @php
                                        $snapshotChip = match (true) {
                                            ! $wallet->credit_enabled => 'cf-status-chip--muted',
                                            $currentStatus === CreditFacilityStatus::Suspended => 'cf-status-chip--warn',
                                            default => 'cf-status-chip--ok',
                                        };
                                    @endphp
                                    <span class="cf-status-chip {{ $snapshotChip }}">
                                        {{ $currentStatus?->label() ?? __('messages.credit_facility_status_none') }}
                                    </span>
                                </dd>
                            </div>
                        </dl>
                    </div>

                    @unless ($showReviewSummary)
                        <div class="space-y-4 border-t border-[var(--cf-border)] pt-4">
                            <p class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.credit_facility_edit_heading') }}
                            </p>

                            <flux:field>
                                <div class="flex items-center justify-between gap-4">
                                    <div class="min-w-0">
                                        <flux:label>{{ __('messages.credit_enabled') }}</flux:label>
                                        <flux:description>
                                            {{ __('messages.credit_facility_enabled_hint') }}
                                        </flux:description>
                                    </div>
                                    <flux:switch wire:model.live="creditEnabled" />
                                </div>
                                <flux:error name="creditEnabled" />
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('messages.credit_limit') }}</flux:label>
                                <flux:input
                                    type="number"
                                    step="0.01"
                                    min="0"
                                    inputmode="decimal"
                                    wire:model.blur="creditLimit"
                                    placeholder="0.00"
                                    autocomplete="off"
                                    name="credit_limit"
                                    dir="ltr"
                                />
                                <flux:error name="creditLimit" />
                                <flux:description>
                                    {{ __('messages.credit_facility_limit_hint', ['currency' => $currency]) }}
                                </flux:description>
                            </flux:field>

                            <flux:field>
                                <flux:label>{{ __('messages.payment_terms') }}</flux:label>
                                <flux:select wire:model.blur="paymentTermsDays" name="payment_terms_days">
                                    <flux:select.option value="">{{ __('messages.credit_facility_terms_placeholder') }}</flux:select.option>
                                    @foreach ($this->paymentTermsOptions as $option)
                                        <flux:select.option value="{{ $option['value'] }}">{{ $option['label'] }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                                <flux:error name="paymentTermsDays" />
                            </flux:field>

                            @if ($creditEnabled)
                                <flux:field>
                                    <flux:label>{{ __('messages.credit_status') }}</flux:label>
                                    <flux:select wire:model.blur="creditStatus" name="credit_status">
                                        @foreach (\App\Enums\CreditFacilityStatus::cases() as $statusOption)
                                            <flux:select.option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</flux:select.option>
                                        @endforeach
                                    </flux:select>
                                    <flux:error name="creditStatus" />
                                    <flux:description>
                                        {{ __('messages.credit_facility_status_hint') }}
                                    </flux:description>
                                </flux:field>
                            @else
                                <flux:field>
                                    <flux:label>{{ __('messages.credit_status') }}</flux:label>
                                    <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                                        {{ __('messages.credit_facility_status_none') }}
                                    </flux:text>
                                    <flux:description>
                                        {{ __('messages.credit_facility_status_disabled_hint') }}
                                    </flux:description>
                                </flux:field>
                            @endif

                            <flux:button
                                type="button"
                                variant="primary"
                                wire:click="reviewFacility"
                                wire:loading.attr="disabled"
                                wire:target="reviewFacility"
                                class="w-full sm:w-auto"
                            >
                                <span wire:loading.remove wire:target="reviewFacility">
                                    {{ __('messages.credit_facility_review') }}
                                </span>
                                <span wire:loading wire:target="reviewFacility">
                                    {{ __('messages.processing') }}
                                </span>
                            </flux:button>
                        </div>
                    @endunless
                </section>

                @if ($showReviewSummary)
                    <section
                        class="cf-detail-shell--review space-y-5 rounded-xl border border-[var(--cf-border)] px-4 py-4"
                        aria-labelledby="cf-section-summary"
                    >
                        <div class="space-y-1">
                            <h2 id="cf-section-summary" class="text-xs font-semibold tracking-[0.18em] text-[var(--cf-muted-foreground)] uppercase">
                                {{ __('messages.credit_facility_review_summary') }}
                            </h2>
                            <flux:heading size="sm" class="text-pretty text-[var(--cf-foreground)]">
                                {{ __('messages.credit_facility_confirm_heading') }}
                            </flux:heading>
                            <flux:text class="text-sm text-[var(--cf-muted-foreground)]">
                                {{ __('messages.credit_facility_confirm_hint', ['name' => $this->selectedUser->name]) }}
                            </flux:text>
                        </div>

                        <dl class="divide-y divide-[var(--cf-border)] border-y border-[var(--cf-border)]">
                            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.previous_credit_limit') }}
                                </dt>
                                <dd class="text-sm font-semibold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                    {{ $symbol }}{{ number_format((float) $wallet->credit_limit, 2) }} {{ $currency }}
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.new_credit_limit') }}
                                </dt>
                                <dd class="text-sm font-semibold tabular-nums text-[var(--cf-primary)]" dir="ltr">
                                    {{ $symbol }}{{ number_format((float) $this->normalizedCreditLimit, 2) }} {{ $currency }}
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.outstanding_debt') }}
                                </dt>
                                <dd class="text-sm font-semibold tabular-nums {{ $isOverdrawn ? 'text-[var(--cf-destructive)]' : 'text-[var(--cf-foreground)]' }}" dir="ltr">
                                    {{ $symbol }}{{ number_format((float) $outstandingDebt, 2) }} {{ $currency }}
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.available_credit_after') }}
                                </dt>
                                <dd class="text-base font-bold tabular-nums text-[var(--cf-foreground)]" dir="ltr">
                                    {{ $symbol }}{{ number_format((float) $this->projectedAvailableCredit, 2) }} {{ $currency }}
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.credit_enabled') }}
                                </dt>
                                <dd class="text-sm font-medium text-[var(--cf-foreground)]">
                                    {{ ($wallet->credit_enabled ? __('messages.yes') : __('messages.no')) }}
                                    <span class="mx-1 text-[var(--cf-muted-foreground)]" aria-hidden="true">→</span>
                                    {{ $creditEnabled ? __('messages.yes') : __('messages.no') }}
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.payment_terms') }}
                                </dt>
                                <dd class="text-sm font-medium text-[var(--cf-foreground)]">
                                    {{ $wallet->payment_terms_days ? __('messages.credit_facility_net_label', ['days' => $wallet->payment_terms_days]) : __('messages.credit_facility_terms_none') }}
                                    <span class="mx-1 text-[var(--cf-muted-foreground)]" aria-hidden="true">→</span>
                                    {{ $paymentTermsDays ? __('messages.credit_facility_net_label', ['days' => $paymentTermsDays]) : __('messages.credit_facility_terms_none') }}
                                </dd>
                            </div>
                            <div class="grid gap-1 py-3 sm:grid-cols-[9rem_1fr] sm:items-baseline">
                                <dt class="text-xs font-semibold tracking-wide text-[var(--cf-muted-foreground)] uppercase">
                                    {{ __('messages.credit_status') }}
                                </dt>
                                <dd class="text-sm font-medium text-[var(--cf-foreground)]">
                                    {{ $currentStatus?->label() ?? __('messages.credit_facility_status_none') }}
                                    <span class="mx-1 text-[var(--cf-muted-foreground)]" aria-hidden="true">→</span>
                                    @if ($creditEnabled && $creditStatus)
                                        {{ (\App\Enums\CreditFacilityStatus::tryFrom($creditStatus))?->label() ?? __('messages.credit_facility_status_none') }}
                                    @else
                                        {{ __('messages.credit_facility_status_none') }}
                                    @endif
                                </dd>
                            </div>
                        </dl>

                        <flux:checkbox
                            wire:model="confirmAcknowledged"
                            :label="__('messages.credit_facility_confirm_checkbox')"
                        />
                        @error('confirmAcknowledged')
                            <flux:text class="text-sm text-[var(--cf-destructive)]">{{ $message }}</flux:text>
                        @enderror

                        <div class="flex flex-wrap gap-2">
                            <flux:button
                                type="button"
                                variant="primary"
                                wire:click="confirmFacility"
                                wire:loading.attr="disabled"
                                wire:target="confirmFacility"
                            >
                                <span wire:loading.remove wire:target="confirmFacility">
                                    {{ __('messages.credit_facility_confirm_save') }}
                                </span>
                                <span wire:loading wire:target="confirmFacility">
                                    {{ __('messages.processing') }}
                                </span>
                            </flux:button>
                            <flux:button
                                type="button"
                                variant="ghost"
                                wire:click="cancelReview"
                                wire:loading.attr="disabled"
                                wire:target="confirmFacility"
                            >
                                {{ __('messages.back') }}
                            </flux:button>
                        </div>
                    </section>
                @endif
            @endif
        </aside>
    </div>
</div>
