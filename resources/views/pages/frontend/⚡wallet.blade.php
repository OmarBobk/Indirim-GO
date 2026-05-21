<?php

use App\Actions\Loyalty\EvaluateLoyaltyForUserAction;
use App\Actions\Topups\CreateTopupRequestAction;
use App\Enums\OrderStatus;
use App\Enums\TopupMethod;
use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Events\TopupRequestsChanged;
use App\Models\TopupProof;
use App\Models\TopupRequest;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Fulfillment;
use App\Models\LoyaltySetting;
use App\Models\LoyaltyTierConfig;
use App\Models\WebsiteSetting;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\TopupRequestedNotification;
use App\Services\LoyaltySpendService;
use App\Services\NotificationRecipientService;
use App\Support\FrontendMoney;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;
use Masmerise\Toaster\Toastable;

new #[Layout('layouts::frontend')] class extends Component
{
    use Toastable;
    use WithFileUploads;

    public ?string $topupAmount = null;
    public string $topupMethod = TopupMethod::ShamCash->value;

    /** @var \Illuminate\Http\UploadedFile|null */
    public $proofFile = null;

    public bool $attachProof = false;

    public ?string $noticeMessage = null;
    public ?string $noticeVariant = null;

    public function mount(EvaluateLoyaltyForUserAction $evaluateLoyalty): void
    {
        abort_unless(auth()->check(), 403);
        $user = auth()->user();
        if ($user?->loyaltyRole() !== null) {
            $evaluateLoyalty->handle($user);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function rules(): array
    {
        $rules = [
            'topupAmount' => ['required', 'numeric', 'min:0.01'],
            'topupMethod' => ['required', Rule::in(TopupMethod::values())],
            'attachProof' => ['boolean'],
        ];

        if ($this->attachProof) {
            $rules['proofFile'] = ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:5120'];
        } else {
            $rules['proofFile'] = ['nullable'];
        }

        return $rules;
    }

    public function updatedAttachProof(bool $value): void
    {
        if (! $value) {
            $this->reset('proofFile');
        }
    }

    public function submitTopup(): void
    {
        $this->reset('noticeMessage', 'noticeVariant');

        $validated = $this->validate();
        $user = auth()->user();
        $wallet = Wallet::forUser($user);
        $requestAmount = $this->normalizeTopupAmountForWalletCurrency((float) $validated['topupAmount'], $user, $wallet);

        $hasPending = TopupRequest::query()
            ->where('user_id', $user->id)
            ->where('status', TopupRequestStatus::Pending)
            ->exists();

        if ($hasPending) {
            $this->noticeVariant = 'danger';
            $this->noticeMessage = __('messages.topup_request_pending');
            $this->warning(__('messages.topup_request_pending'));

            return;
        }

        DB::transaction(function () use ($user, $wallet, $validated, $requestAmount): void {
            $topupRequest = app(CreateTopupRequestAction::class)->handle([
                'user_id' => $user->id,
                'wallet_id' => $wallet->id,
                'method' => TopupMethod::from($validated['topupMethod']),
                'amount' => $requestAmount,
                'currency' => $wallet->currency,
                'status' => TopupRequestStatus::Pending,
            ]);

            if ($this->attachProof && $this->proofFile !== null) {
                $ext = $this->proofFile->getClientOriginalExtension() ?: $this->proofFile->guessExtension() ?? 'bin';
                $filename = Str::uuid()->toString().'.'.$ext;
                $dir = 'topups/proofs/'.$topupRequest->id;

                $fileOriginalName = $this->proofFile->getClientOriginalName();
                $mimeType = $this->proofFile->getMimeType();
                $sizeBytes = $this->proofFile->getSize();

                $path = $this->proofFile->storeAs($dir, $filename, 'local');

                if ($path === false) {
                    throw new \RuntimeException('Failed to store top-up proof file.');
                }

                TopupProof::create([
                    'topup_request_id' => $topupRequest->id,
                    'file_path' => $path,
                    'file_original_name' => $fileOriginalName,
                    'mime_type' => $mimeType,
                    'size_bytes' => $sizeBytes,
                ]);
            }

            activity()
                ->inLog('payments')
                ->event('topup.requested')
                ->performedOn($topupRequest)
                ->causedBy($user)
                ->withProperties([
                    'topup_request_id' => $topupRequest->id,
                    'wallet_id' => $wallet->id,
                    'user_id' => $user->id,
                    'amount' => $topupRequest->amount,
                    'currency' => $wallet->currency,
                    'method' => $topupRequest->method->value,
                ])
                ->log('Topup requested');

            $topupRequestId = $topupRequest->id;
            DB::afterCommit(function () use ($topupRequestId): void {
                $request = TopupRequest::query()->find($topupRequestId);
                if ($request === null) {
                    return;
                }

                event(new TopupRequestsChanged($request->id, 'created'));

                $notification = TopupRequestedNotification::fromTopupRequest($request);
                app(NotificationRecipientService::class)->adminUsers()->each(fn ($admin) => $admin->notify($notification));
            });
        });

        $this->reset('topupAmount', 'proofFile', 'attachProof');
        $this->topupMethod = TopupMethod::ShamCash->value;

        $this->noticeVariant = 'success';
        $this->noticeMessage = __('messages.topup_request_created');
        $this->success(__('messages.topup_request_created'));
    }

    private function normalizeTopupAmountForWalletCurrency(float $enteredAmount, \App\Models\User $user, Wallet $wallet): float
    {
        if (
            strtoupper((string) $user->preferred_currency) === 'TRY'
            && strtoupper((string) $wallet->currency) === 'USD'
        ) {
            $rate = WebsiteSetting::getUsdTryRate();
            if ($rate !== null && $rate > 0) {
                // Never round down TRY customer requests when converting to USD cents.
                $tryCents = (int) round($enteredAmount * 100);
                $usdCents = (int) ceil($tryCents / $rate);

                return $usdCents / 100;
            }
        }

        return $enteredAmount;
    }

    public function getWalletProperty(): Wallet
    {
        return Wallet::forUser(auth()->user());
    }

    #[Computed]
    public function loyaltyRollingSpend(): float
    {
        $windowDays = LoyaltySetting::getRollingWindowDays();

        return app(LoyaltySpendService::class)->computeRollingSpend(auth()->user(), $windowDays);
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
    public function loyaltyNextTier(): ?LoyaltyTierConfig
    {
        $user = auth()->user();
        $role = $user?->loyaltyRole();
        if ($role === null) {
            return null;
        }
        $spend = $this->loyaltyRollingSpend;

        return LoyaltyTierConfig::query()
            ->forRole($role)
            ->where('min_spend', '>', $spend)
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

    /**
     * @return Collection<int, WalletTransaction>
     */
    public function getWalletTransactionsProperty(): Collection
    {
        $transactions = WalletTransaction::query()
            ->where('wallet_id', $this->wallet->id)
            ->with('reference')
            ->latest('created_at')
            ->limit(100)
            ->get();

        // Eager load order items for Purchase (Order) and Refund (Fulfillment) transactions
        $orderIds = $transactions
            ->filter(fn ($t) => $t->reference_type === Order::class)
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();

        $fulfillmentIds = $transactions
            ->filter(fn ($t) => $t->reference_type === Fulfillment::class)
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();

        if (! empty($orderIds)) {
            $orders = Order::with('items')->whereIn('id', $orderIds)->get()->keyBy('id');
            foreach ($transactions as $t) {
                if ($t->reference_type === Order::class && isset($orders[$t->reference_id])) {
                    $t->setRelation('reference', $orders[$t->reference_id]);
                }
            }
        }

        if (! empty($fulfillmentIds)) {
            $fulfillments = Fulfillment::with('orderItem.order')->whereIn('id', $fulfillmentIds)->get()->keyBy('id');
            foreach ($transactions as $t) {
                if ($t->reference_type === Fulfillment::class && isset($fulfillments[$t->reference_id])) {
                    $t->setRelation('reference', $fulfillments[$t->reference_id]);
                }
            }
        }

        // Eager load OrderItem references for Refund transactions
        $orderItemIds = $transactions
            ->filter(fn ($t) => $t->reference_type === OrderItem::class)
            ->pluck('reference_id')
            ->unique()
            ->values()
            ->all();

        if (! empty($orderItemIds)) {
            $orderItems = OrderItem::with('order')->whereIn('id', $orderItemIds)->get()->keyBy('id');
            foreach ($transactions as $t) {
                if ($t->reference_type === OrderItem::class && isset($orderItems[$t->reference_id])) {
                    $t->setRelation('reference', $orderItems[$t->reference_id]);
                }
            }
        }

        return $transactions;
    }

    /**
     * @return Collection<int, TopupRequest>
     */
    public function getTopupRequestsProperty(): Collection
    {
        return TopupRequest::query()
            ->where('user_id', auth()->id())
            ->with('proofs')
            ->latest('created_at')
            ->limit(10)
            ->get();
    }

    /**
     * @return array<string, string>
     */
    public function getTopupMethodOptionsProperty(): array
    {
        return [
            TopupMethod::ShamCash->value => __('messages.topup_method_sham_cash'),
            TopupMethod::EftTransfer->value => __('messages.topup_method_eft_transfer'),
        ];
    }

    protected function orderStatusLabel(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::PendingPayment => __('messages.order_status_pending_payment'),
            OrderStatus::Paid => __('messages.order_status_paid'),
            OrderStatus::Processing => __('messages.order_status_processing'),
            OrderStatus::Fulfilled => __('messages.order_status_fulfilled'),
            OrderStatus::Failed => __('messages.order_status_failed'),
            OrderStatus::Refunded => __('messages.order_status_refunded'),
            OrderStatus::Cancelled => __('messages.order_status_cancelled'),
        };
    }

    protected function orderStatusColor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Fulfilled => 'green',
            OrderStatus::Failed, OrderStatus::Cancelled => 'red',
            OrderStatus::Refunded => 'gray',
            OrderStatus::Paid => 'blue',
            default => 'amber',
        };
    }

    /**
     * @return array{label: string, url: ?string}
     */
    protected function transactionDetails(WalletTransaction $transaction): array
    {
        if ($transaction->reference_type === Order::class && $transaction->reference instanceof Order) {
            $order = $transaction->reference;
            $itemsLabel = $this->formatOrderItemsLabel($order->items);
            $orderUrl = route('orders.show', $order->order_number);

            return [
                'label' => $itemsLabel ?: __('messages.order_number').': '.$order->order_number,
                'url' => $orderUrl,
            ];
        }

        if ($transaction->type === WalletTransactionType::Refund) {
            $orderItem = $this->resolveRefundOrderItem($transaction);
            $orderNumber = data_get($transaction->meta, 'order_number');

            if ($orderItem !== null) {
                $itemLabel = $orderItem->name.($orderItem->quantity > 1 ? ' (×'.$orderItem->quantity.')' : '');
                $order = $orderItem->order;
                $orderNumber = $orderNumber ?? ($order?->order_number);

                return [
                    'label' => $itemLabel,
                    'url' => $orderNumber ? route('orders.show', $orderNumber) : null,
                ];
            }

            if ($orderNumber !== null) {
                return [
                    'label' => __('messages.order_number').': '.$orderNumber,
                    'url' => route('orders.show', $orderNumber),
                ];
            }

            return [
                'label' => __('messages.order').' #'.data_get($transaction->meta, 'order_id', $transaction->reference_id),
                'url' => null,
            ];
        }

        if ($transaction->reference_type === TopupRequest::class) {
            $methodValue = data_get($transaction->meta, 'method');
            $method = $methodValue ? TopupMethod::tryFrom($methodValue) : null;

            if ($method === null && $transaction->reference instanceof TopupRequest) {
                $method = $transaction->reference->method;
            }

            $label = $method
                ? __('messages.topup_method_'.$method->value)
                : __('messages.topup_request');

            return [
                'label' => __('messages.topup_request').': '.$label,
                'url' => null,
            ];
        }

        return [
            'label' => __('messages.no_details'),
            'url' => null,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, OrderItem>  $items
     */
    protected function formatOrderItemsLabel($items): string
    {
        if ($items === null || $items->isEmpty()) {
            return '';
        }

        return $items->map(fn (OrderItem $item) => $item->name.($item->quantity > 1 ? ' (×'.$item->quantity.')' : ''))->join(', ');
    }

    protected function resolveRefundOrderItem(WalletTransaction $transaction): ?OrderItem
    {
        if ($transaction->reference_type === OrderItem::class && $transaction->reference instanceof OrderItem) {
            return $transaction->reference;
        }

        if ($transaction->reference_type === Fulfillment::class && $transaction->reference instanceof Fulfillment) {
            return $transaction->reference->orderItem;
        }

        return null;
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.wallet'));
    }
};
?>

@php
    $money = FrontendMoney::for(auth()->user());
    $topupDisplayCurrency = 'USD';
    $topupRate = \App\Models\WebsiteSetting::getUsdTryRate();
    if (strtoupper((string) (auth()->user()?->preferred_currency ?? 'USD')) === 'TRY' && $topupRate !== null && $topupRate > 0) {
        $topupDisplayCurrency = 'TRY';
    }
    $topupCurrencySign = $topupDisplayCurrency === 'TRY' ? '₺' : '$';
@endphp

<div class="mx-auto w-full max-w-7xl px-3 py-6 sm:px-0 sm:py-10">
    <div class="mb-4 flex items-center">
        <x-back-button />
    </div>
    <div class="grid gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            @if ($this->loyaltyCurrentTierConfig !== null)
                <section>
                    <x-loyalty.tier-card
                        :current-tier-name="auth()->user()?->loyalty_tier?->value ?? 'bronze'"
                        :discount-percent="(float) $this->loyaltyCurrentTierConfig->discount_percentage"
                        :rolling-spend="$this->loyaltyRollingSpend"
                        :next-tier-name="$this->loyaltyNextTier?->name"
                        :next-tier-min-spend="$this->loyaltyNextTier ? (float) $this->loyaltyNextTier->min_spend : null"
                        :amount-to-next="$this->loyaltyAmountToNextTier"
                        :progress-percent="$this->loyaltyProgressPercent"
                        :window-days="\App\Models\LoyaltySetting::getRollingWindowDays()"
                        layout="full"
                    />
                </section>
            @endif

            <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.wallet_balance') }}
                    </flux:heading>
                </div>
                <div class="mt-4 text-3xl font-semibold text-zinc-900 dark:text-zinc-100" dir="ltr">
                    @if(\App\Models\WebsiteSetting::getPricesVisible())
                        {{ $money->format((float) $this->wallet->balance, 'USD', 2) }}
                    @else
                        —
                    @endif
                </div>
                <flux:text class="mt-2 text-sm text-zinc-500 dark:text-zinc-400">
                    {{ __('messages.wallet_balance_hint') }}
                </flux:text>
            </section>

            {{-- Request top-up form: visible on mobile only, placed right after wallet balance --}}
            <section class="block rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6 lg:hidden">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.request_topup') }}
                </flux:heading>
                @if ($noticeMessage)
                    <div class="mt-4">
                        <flux:callout variant="{{ $noticeVariant === 'danger' ? 'danger' : 'subtle' }}" icon="{{ $noticeVariant === 'danger' ? 'exclamation-triangle' : 'check-circle' }}">
                            {{ $noticeMessage }}
                        </flux:callout>
                    </div>
                @endif
                <form class="mt-4 space-y-4" wire:submit.prevent="submitTopup">
                    <div class="grid gap-2">
                        <flux:input
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            name="topupAmountMobile"
                            label="{{ __('messages.amount').' ('.$topupCurrencySign.')' }}"
                            wire:model.defer="topupAmount"
                            placeholder="0.00"
                        />
                    </div>
                    <div class="grid gap-2">
                        <flux:select
                            name="topupMethodMobile"
                            class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            label="{{ __('messages.method') }}"
                            wire:model.defer="topupMethod"
                        >
                            @foreach ($this->topupMethodOptions as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-100 bg-zinc-50 px-3 py-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                        <div class="min-w-0 flex-1 space-y-1 pe-2">
                            <flux:text class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('messages.topup_attach_proof_toggle') }}</flux:text>
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.topup_attach_proof_hint') }}</flux:text>
                        </div>
                        <flux:switch
                            class="shrink-0 focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            wire:model.live="attachProof"
                        />
                    </div>
                    @if ($attachProof)
                        <div class="grid gap-2">
                            <flux:field>
                                <flux:label>{{ __('messages.proof_of_payment') }}</flux:label>
                                <input
                                    type="file"
                                    name="proofFileMobile"
                                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                                    wire:model.defer="proofFile"
                                    class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600"
                                />
                            </flux:field>
                            @error('proofFile')
                                <flux:text class="text-xs text-red-600">{{ $message }}</flux:text>
                            @enderror
                        </div>
                    @endif
                    <flux:button
                        type="submit"
                        variant="primary"
                        class="w-full justify-center !bg-accent !text-accent-foreground hover:!bg-accent-hover"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove>{{ __('messages.submit_topup') }}</span>
                        <span wire:loading>{{ __('messages.please_wait') }}</span>
                    </flux:button>
                </form>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.wallet_transactions') }}
                    </flux:heading>
                </div>

                <div class="mt-4">
                    @if ($this->walletTransactions->isEmpty())
                        <div class="flex flex-col items-center justify-center gap-2 rounded-xl border border-zinc-100 px-6 py-12 text-center dark:border-zinc-800">
                            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                                {{ __('messages.no_wallet_transactions') }}
                            </flux:heading>
                            <flux:text class="text-zinc-600 dark:text-zinc-400">
                                {{ __('messages.no_wallet_transactions_hint') }}
                            </flux:text>
                        </div>
                    @else
                        <div class="grid gap-3 sm:hidden" role="list" aria-label="{{ __('messages.wallet_transactions') }}">
                            @foreach ($this->walletTransactions as $transaction)
                                @php
                                    $typeLabel = match ($transaction->type) {
                                        WalletTransactionType::Topup => __('messages.wallet_transaction_type_topup'),
                                        WalletTransactionType::Purchase => __('messages.wallet_transaction_type_purchase'),
                                        WalletTransactionType::Refund => __('messages.wallet_transaction_type_refund'),
                                        WalletTransactionType::Adjustment => __('messages.wallet_transaction_type_adjustment'),
                                        WalletTransactionType::Settlement => __('messages.wallet_transaction_type_settlement'),
                                        WalletTransactionType::CommissionCredit => __('messages.wallet_transaction_type_commission_credit'),
                                        default => $transaction->type->value,
                                    };
                                    $directionLabel = $transaction->direction === WalletTransactionDirection::Credit
                                        ? __('messages.credit')
                                        : __('messages.debit');
                                    $directionColor = $transaction->direction === WalletTransactionDirection::Credit ? 'green' : 'red';
                                    $details = $this->transactionDetails($transaction);
                                    $note = data_get($transaction->meta, 'note');
                                    $statusColor = match ($transaction->status) {
                                        WalletTransaction::STATUS_POSTED => 'green',
                                        WalletTransaction::STATUS_REJECTED => 'red',
                                        default => 'amber',
                                    };
                                    $borderColor = $transaction->direction === WalletTransactionDirection::Credit
                                        ? 'border-s-emerald-500 dark:border-s-emerald-600'
                                        : 'border-s-red-700 dark:border-s-red-800';
                                    $amountColor = $transaction->direction === WalletTransactionDirection::Credit
                                        ? 'text-emerald-600 dark:text-emerald-400'
                                        : 'text-red-700 dark:text-red-400';
                                @endphp
                                <article
                                    class="relative flex flex-col gap-3 rounded-xl border border-zinc-200 border-s-4 {{ $borderColor }} bg-white p-4 dark:border-zinc-700 dark:bg-zinc-900"
                                    role="listitem"
                                >
                                    {{-- Primary: amount + direction --}}
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-xl font-bold tabular-nums {{ $amountColor }}" dir="ltr">
                                            @if(\App\Models\WebsiteSetting::getPricesVisible())
                                                {{ $transaction->direction === WalletTransactionDirection::Credit ? '+' : '−' }}{{ $money->format((float) $transaction->amount, 'USD', 2) }}
                                            @else
                                                —
                                            @endif
                                        </span>
                                        <div class="flex items-center gap-2 shrink-0">
                                            <flux:badge color="{{ $directionColor }}" class="text-xs">{{ $directionLabel }}</flux:badge>
                                            <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">{{ __('messages.'.$transaction->status) }}</span>
                                        </div>
                                    </div>

                                    {{-- Type --}}
                                    <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">
                                        {{ $typeLabel }}
                                    </div>

                                    {{-- Details (what) --}}
                                    <div>
                                        <span class="sr-only">{{ __('messages.details') }}: </span>
                                        @if ($details['url'])
                                            <a
                                                href="{{ $details['url'] }}"
                                                wire:navigate
                                                class="inline-flex items-center gap-1 text-sm font-medium text-(--color-accent) hover:underline focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--color-accent)"
                                            >
                                                {{ $details['label'] }}
                                                <flux:icon icon="chevron-right" class="size-3.5 shrink-0 rtl:rotate-180" />
                                            </a>
                                        @else
                                            <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $details['label'] }}</span>
                                        @endif
                                    </div>

                                    @if ($note)
                                        <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</p>
                                    @endif

                                    {{-- Date --}}
                                    <time class="text-xs text-zinc-500 dark:text-zinc-400" datetime="{{ $transaction->created_at?->toIso8601String() ?? '' }}">
                                        {{ $transaction->created_at?->format('M d, Y H:i') ?? '—' }}
                                    </time>
                                </article>
                            @endforeach
                        </div>

                        <div class="hidden overflow-hidden rounded-xl border border-zinc-100 dark:border-zinc-800 sm:block">
                            <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                                <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                                    <tr>
                                        <th class="px-5 py-3 text-start font-semibold">{{ __('messages.type') }}</th>
                                        <th class="px-5 py-3 text-start font-semibold">{{ __('messages.direction') }}</th>
                                        <th class="px-5 py-3 text-start font-semibold">{{ __('messages.amount') }}</th>
                                        <th class="px-5 py-3 text-start font-semibold">{{ __('messages.status') }}</th>
                                        <th class="px-5 py-3 text-start font-semibold">{{ __('messages.details') }}</th>
                                        <th class="px-5 py-3 text-start font-semibold">{{ __('messages.note') }}</th>
                                        <th class="px-5 py-3 text-start font-semibold">{{ __('messages.created') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                                    @foreach ($this->walletTransactions as $transaction)
                                        @php
                                            $typeLabel = match ($transaction->type) {
                                                WalletTransactionType::Topup => __('messages.wallet_transaction_type_topup'),
                                                WalletTransactionType::Purchase => __('messages.wallet_transaction_type_purchase'),
                                                WalletTransactionType::Refund => __('messages.wallet_transaction_type_refund'),
                                                WalletTransactionType::Adjustment => __('messages.wallet_transaction_type_adjustment'),
                                                WalletTransactionType::Settlement => __('messages.wallet_transaction_type_settlement'),
                                                WalletTransactionType::CommissionCredit => __('messages.wallet_transaction_type_commission_credit'),
                                                default => $transaction->type->value,
                                            };
                                            $directionLabel = $transaction->direction === WalletTransactionDirection::Credit
                                                ? __('messages.credit')
                                                : __('messages.debit');
                                            $directionColor = $transaction->direction === WalletTransactionDirection::Credit ? 'green' : 'red';
                                            $details = $this->transactionDetails($transaction);
                                            $note = data_get($transaction->meta, 'note');
                                            $statusColor = match ($transaction->status) {
                                                WalletTransaction::STATUS_POSTED => 'green',
                                                WalletTransaction::STATUS_REJECTED => 'red',
                                                default => 'amber',
                                            };
                                        @endphp
                                        <tr class="transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60">
                                            <td class="px-5 py-4 text-zinc-700 dark:text-zinc-200">
                                                {{ $typeLabel }}
                                            </td>
                                            <td class="px-5 py-4">
                                                <flux:badge color="{{ $directionColor }}">{{ $directionLabel }}</flux:badge>
                                            </td>
                                            <td class="px-5 py-4 text-zinc-700 dark:text-zinc-200" dir="ltr">
                                                @if(\App\Models\WebsiteSetting::getPricesVisible())
                                                    {{ $money->format((float) $transaction->amount, 'USD', 2) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                                <flux:badge color="{{ $statusColor }}">
                                                    {{ __('messages.'.$transaction->status) }}
                                                </flux:badge>
                                            </td>
                                            <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                                @if ($details['url'])
                                                    <a
                                                        href="{{ $details['url'] }}"
                                                        wire:navigate
                                                        class="font-semibold text-zinc-900 hover:underline dark:text-zinc-100"
                                                    >
                                                        {{ $details['label'] }}
                                                    </a>
                                                @else
                                                    <span>{{ $details['label'] }}</span>
                                                @endif
                                            </td>
                                            <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                                {{ $note ?: '—' }}
                                            </td>
                                            <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                                {{ $transaction->created_at?->format('M d, Y H:i') ?? '—' }}
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                            </div>
                        </div>
                    @endif
                </div>
            </section>

            <x-timeline :entity="$this->wallet" />
        </div>

        <aside class="space-y-6">
            {{-- Request top-up form: visible on desktop (lg+) only --}}
            <section class="hidden rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6 lg:block">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.request_topup') }}
                </flux:heading>

                @if ($noticeMessage)
                    <div class="mt-4">
                        <flux:callout variant="{{ $noticeVariant === 'danger' ? 'danger' : 'subtle' }}" icon="{{ $noticeVariant === 'danger' ? 'exclamation-triangle' : 'check-circle' }}">
                            {{ $noticeMessage }}
                        </flux:callout>
                    </div>
                @endif

                <form class="mt-4 space-y-4" wire:submit.prevent="submitTopup">
                    <div class="grid gap-2">
                        <flux:input
                            class:input="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            name="topupAmount"
                            label="{{ __('messages.amount').' ('.$topupCurrencySign.')' }}"
                            wire:model.defer="topupAmount"
                            placeholder="0.00"
                        />
                    </div>

                    <div class="grid gap-2">
                        <flux:select
                            name="topupMethod"
                            class="focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            label="{{ __('messages.method') }}"
                            wire:model.defer="topupMethod"
                        >
                            @foreach ($this->topupMethodOptions as $value => $label)
                                <flux:select.option value="{{ $value }}">{{ $label }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        @error('topupMethod')
                            <flux:text class="text-xs text-red-600">{{ $message }}</flux:text>
                        @enderror
                    </div>

                    <div class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-zinc-100 bg-zinc-50 px-3 py-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                        <div class="min-w-0 flex-1 space-y-1 pe-2">
                            <flux:text class="text-sm font-medium text-zinc-800 dark:text-zinc-200">{{ __('messages.topup_attach_proof_toggle') }}</flux:text>
                            <flux:text class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.topup_attach_proof_hint') }}</flux:text>
                        </div>
                        <flux:switch
                            class="shrink-0 focus:!border-(--color-accent) focus:!border-1 focus:!ring-0 focus:!outline-none focus:!ring-offset-0"
                            wire:model.live="attachProof"
                        />
                    </div>
                    @if ($attachProof)
                        <div class="grid gap-2">
                            <flux:field>
                                <flux:label>{{ __('messages.proof_of_payment') }}</flux:label>
                                <input
                                    type="file"
                                    name="proofFile"
                                    accept=".jpg,.jpeg,.png,.webp,.pdf"
                                    wire:model.defer="proofFile"
                                    class="block w-full text-sm text-zinc-600 file:mr-4 file:rounded-lg file:border-0 file:bg-zinc-100 file:px-4 file:py-2 file:text-sm file:font-medium file:text-zinc-800 hover:file:bg-zinc-200 dark:text-zinc-400 dark:file:bg-zinc-700 dark:file:text-zinc-200 dark:hover:file:bg-zinc-600"
                                />
                            </flux:field>
                            @error('proofFile')
                                <flux:text class="text-xs text-red-600">{{ $message }}</flux:text>
                            @enderror
                        </div>
                    @endif

                    <flux:button
                        type="submit"
                        variant="primary"
                        class="w-full justify-center !bg-accent !text-accent-foreground hover:!bg-accent-hover"
                        wire:loading.attr="disabled"
                    >
                        <span wire:loading.remove>{{ __('messages.submit_topup') }}</span>
                        <span wire:loading>{{ __('messages.please_wait') }}</span>
                    </flux:button>
                </form>
            </section>

            <section class="rounded-2xl border border-zinc-200 bg-white p-4 shadow-sm dark:border-zinc-700 dark:bg-zinc-900 sm:p-6">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.topup_requests') }}
                </flux:heading>

                <div class="mt-4 space-y-3">
                    @if ($this->topupRequests->isEmpty())
                        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('messages.no_topups_yet') }}
                        </flux:text>
                    @else
                        @foreach ($this->topupRequests as $topupRequest)
                            @php
                                $statusColor = match ($topupRequest->status) {
                                    TopupRequestStatus::Approved => 'green',
                                    TopupRequestStatus::Rejected => 'red',
                                    default => 'amber',
                                };
                            @endphp
                            <div class="flex items-center justify-between gap-3 rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                                <div>
                                    <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                        @if(\App\Models\WebsiteSetting::getPricesVisible())
                                            {{ $money->format((float) $topupRequest->amount, (string) $topupRequest->currency, 2) }}
                                        @else
                                            —
                                        @endif
                                    </div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        {{ $topupRequest->created_at?->format('M d, Y') ?? '—' }}
                                    </div>
                                </div>
                                <div class="flex items-center gap-2">
                                    @if ($topupRequest->proofs->isNotEmpty())
                                        <flux:button
                                            as="a"
                                            href="{{ route('topup-proofs.show', $topupRequest->proofs->first()) }}"
                                            variant="ghost"
                                            size="sm"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                        >
                                            {{ __('messages.view_proof') }}
                                        </flux:button>
                                    @endif
                                    <flux:badge color="{{ $statusColor }}">
                                        {{ __('messages.'.$topupRequest->status->value) }}
                                    </flux:badge>
                                </div>
                            </div>
                        @endforeach
                    @endif
                </div>
            </section>
        </aside>
    </div>
</div>
