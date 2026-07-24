<?php

use App\Actions\Loyalty\EvaluateLoyaltyForUserAction;
use App\Enums\OrderStatus;
use App\Enums\TopupRequestStatus;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\LoyaltySetting;
use App\Models\LoyaltyTierConfig;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\TopupRequest;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\LoyaltySpendService;
use App\Support\FrontendMoney;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Masmerise\Toaster\Toastable;

new #[Layout('layouts::frontend')] class extends Component
{
    use Toastable;

    public function mount(EvaluateLoyaltyForUserAction $evaluateLoyalty): void
    {
        abort_unless(auth()->check(), 403);

        $user = auth()->user();

        if ($user?->loyaltyRole() !== null) {
            $evaluateLoyalty->handle($user);
        }

        if (session()->pull('topup_submitted')) {
            $this->success(__('messages.topup_request_created'));
        }
    }

    public function getWalletProperty(): Wallet
    {
        return Wallet::forUser(auth()->user());
    }

    #[Computed]
    public function hasPendingTopup(): bool
    {
        return TopupRequest::query()
            ->where('user_id', auth()->id())
            ->where('status', TopupRequestStatus::Pending)
            ->exists();
    }

    #[Computed]
    public function pendingTopupRequest(): ?TopupRequest
    {
        return TopupRequest::query()
            ->where('user_id', auth()->id())
            ->where('status', TopupRequestStatus::Pending)
            ->latest('id')
            ->first();
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
            $methodName = data_get($transaction->meta, 'payment_method');

            if ($methodName === null && $transaction->reference instanceof TopupRequest) {
                $transaction->reference->loadMissing('paymentMethod');
                $methodName = $transaction->reference->paymentMethod?->name;
            }

            $label = filled($methodName)
                ? $methodName
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
    $wallet = $this->wallet;
    $pricesVisible = \App\Models\WebsiteSetting::getPricesVisible();
@endphp

<x-storefront.page width="work" data-test="wallet-page">
    <div class="storefront-section-stack">
        @if (($resumeUrl = \App\Support\PurchaseResumeIntent::resumeUrl()) !== null)
            <flux:callout variant="subtle" icon="arrow-path" data-test="purchase-resume-banner">
                <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                            {{ __('messages.purchase_resume_banner_title') }}
                        </flux:text>
                        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                            {{ __('messages.purchase_resume_banner_body') }}
                        </flux:text>
                    </div>
                    <flux:button
                        as="a"
                        href="{{ $resumeUrl }}"
                        wire:navigate
                        variant="primary"
                        size="sm"
                        class="shrink-0 !bg-accent !text-accent-foreground hover:!bg-accent-hover"
                        data-test="purchase-resume-continue"
                    >
                        {{ __('messages.purchase_resume_continue') }}
                    </flux:button>
                </div>
            </flux:callout>
        @endif

        @if ($this->loyaltyCurrentTierConfig !== null)
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
        @endif

        <x-wallet.money-summary
            :wallet="$wallet"
            :money="$money"
            :prices-visible="$pricesVisible"
        >
            @unless ($this->hasPendingTopup)
                <flux:button
                    as="a"
                    href="{{ route('wallet.topup') }}"
                    wire:navigate
                    variant="primary"
                    icon="plus"
                    class="shrink-0 !bg-accent !text-accent-foreground hover:!bg-accent-hover"
                    data-test="wallet-add-funds"
                >
                    {{ __('messages.wallet_add_funds') }}
                </flux:button>
            @endunless
        </x-wallet.money-summary>

        @if ($this->hasPendingTopup && $this->pendingTopupRequest)
            <flux:callout variant="warning" icon="clock" data-test="wallet-pending-topup-banner">
                <div class="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <flux:text class="font-medium text-zinc-900 dark:text-zinc-100">
                            {{ __('messages.wallet_topup_pending_banner') }}
                        </flux:text>
                        <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                            @if(\App\Models\WebsiteSetting::getPricesVisible())
                                {{ $money->format((float) $this->pendingTopupRequest->amount, (string) $this->pendingTopupRequest->currency, 2) }}
                            @endif
                            · {{ __('messages.pending') }}
                        </flux:text>
                    </div>
                </div>
            </flux:callout>
        @endif

        @if ($this->topupRequests->isNotEmpty())
            <section class="storefront-card storefront-card--pad-md">
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.topup_requests') }}
                </flux:heading>
                <div class="mt-4 space-y-3">
                    @foreach ($this->topupRequests as $topupRequest)
                        @php
                            $statusColor = \App\Support\CustomerStatusPresentation::badgeColor(
                                (string) ($topupRequest->status?->value ?? $topupRequest->status)
                            );
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
                </div>
            </section>
        @endif

        <section class="storefront-card storefront-card--pad-md">
            <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.wallet_transactions') }}
            </flux:heading>

            <div class="mt-4">
                @if ($this->walletTransactions->isEmpty())
                    <div class="flex flex-col items-center justify-center gap-3 rounded-xl border border-zinc-100 px-6 py-12 text-center dark:border-zinc-800">
                        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                            {{ __('messages.no_wallet_transactions') }}
                        </flux:heading>
                        <flux:text class="text-zinc-600 dark:text-zinc-400">
                            {{ __('messages.no_wallet_transactions_hint') }}
                        </flux:text>
                        @unless ($this->hasPendingTopup)
                            <flux:button
                                as="a"
                                href="{{ route('wallet.topup') }}"
                                wire:navigate
                                variant="primary"
                                size="sm"
                                class="!bg-accent !text-accent-foreground hover:!bg-accent-hover"
                            >
                                {{ __('messages.wallet_add_funds') }}
                            </flux:button>
                        @endunless
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
                                <div class="flex items-center justify-between gap-3">
                                    <span class="text-xl font-bold tabular-nums {{ $amountColor }}" dir="ltr">
                                        @if(\App\Models\WebsiteSetting::getPricesVisible())
                                            {{ $transaction->direction === WalletTransactionDirection::Credit ? '+' : '−' }}{{ $money->format((float) $transaction->amount, 'USD', 2) }}
                                        @else
                                            —
                                        @endif
                                    </span>
                                    <div class="flex shrink-0 items-center gap-2">
                                        <flux:badge color="{{ $directionColor }}" class="text-xs">{{ $directionLabel }}</flux:badge>
                                        <span class="rounded-md bg-zinc-100 px-1.5 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400">{{ __('messages.'.$transaction->status) }}</span>
                                    </div>
                                </div>
                                <div class="text-sm font-medium text-zinc-700 dark:text-zinc-300">{{ $typeLabel }}</div>
                                <div>
                                    @if ($details['url'])
                                        <a href="{{ $details['url'] }}" wire:navigate class="text-sm font-medium text-(--color-accent) hover:underline">{{ $details['label'] }}</a>
                                    @else
                                        <span class="text-sm text-zinc-600 dark:text-zinc-400">{{ $details['label'] }}</span>
                                    @endif
                                </div>
                                @if ($note)
                                    <p class="text-xs text-zinc-500 dark:text-zinc-400">{{ $note }}</p>
                                @endif
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
                                            <td class="px-5 py-4 text-zinc-700 dark:text-zinc-200">{{ $typeLabel }}</td>
                                            <td class="px-5 py-4"><flux:badge color="{{ $directionColor }}">{{ $directionLabel }}</flux:badge></td>
                                            <td class="px-5 py-4 text-zinc-700 dark:text-zinc-200" dir="ltr">
                                                @if(\App\Models\WebsiteSetting::getPricesVisible())
                                                    {{ $money->format((float) $transaction->amount, 'USD', 2) }}
                                                @else
                                                    —
                                                @endif
                                            </td>
                                            <td class="px-5 py-4"><flux:badge color="{{ $statusColor }}">{{ __('messages.'.$transaction->status) }}</flux:badge></td>
                                            <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                                @if ($details['url'])
                                                    <a href="{{ $details['url'] }}" wire:navigate class="font-semibold text-zinc-900 hover:underline dark:text-zinc-100">{{ $details['label'] }}</a>
                                                @else
                                                    {{ $details['label'] }}
                                                @endif
                                            </td>
                                            <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">{{ $note ?: '—' }}</td>
                                            <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">{{ $transaction->created_at?->format('M d, Y H:i') ?? '—' }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    </div>
                @endif
            </div>
        </section>

        <x-timeline :entity="$this->wallet" audience="customer" />
    </div>
</x-storefront.page>
