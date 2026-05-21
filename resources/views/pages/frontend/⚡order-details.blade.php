<?php

use App\Actions\Fulfillments\RetryFulfillment;
use App\Actions\Orders\RefundOrderItem;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\OrderItem;
use App\Support\FrontendMoney;
use App\Support\OrderRequirementLabels;
use App\Models\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Component;

new #[Layout('layouts::frontend')] class extends Component
{
    public Order $order;
    public ?string $actionMessage = null;

    public function mount(Order $order): void
    {
        if ($order->user_id !== auth()->id()) {
            abort(403);
        }

        $this->order = $order->load([
            'items.fulfillments',
            'items.product',
            'items.package.requirements',
        ]);
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.order_details'));
    }

    protected function formatAmount(float|string $amount, string $currency): string
    {
        return FrontendMoney::for(auth()->user())->format($amount, $currency, 2);
    }

    public function retryFulfillment(int $fulfillmentId): void
    {
        $this->reset('actionMessage');

        $fulfillment = $this->order->items
            ->flatMap(fn ($item) => $item->fulfillments)
            ->firstWhere('id', $fulfillmentId);

        if ($fulfillment === null) {
            $this->actionMessage = __('messages.retry_not_allowed');
            return;
        }

        app(RetryFulfillment::class)->handle($fulfillment, 'customer', auth()->id());

        $fulfillment->refresh();
        $this->order->load([
            'items.fulfillments',
            'items.product',
            'items.package.requirements',
        ]);
        $this->actionMessage = $fulfillment->status === FulfillmentStatus::Queued
            ? __('messages.fulfillment_marked_queued')
            : __('messages.retry_not_allowed');
    }

    public function requestRefund(int $fulfillmentId): void
    {
        $this->reset('actionMessage');

        $fulfillment = $this->order->items
            ->flatMap(fn ($item) => $item->fulfillments)
            ->firstWhere('id', $fulfillmentId);

        if ($fulfillment === null) {
            $this->actionMessage = __('messages.refund_not_allowed');
            return;
        }

        try {
            app(RefundOrderItem::class)->handle($fulfillment, auth()->id());
        } catch (ValidationException $exception) {
            $this->actionMessage = collect($exception->errors())->flatten()->first()
                ?? __('messages.refund_not_allowed');
            return;
        }

        $this->order->load([
            'items.fulfillments',
            'items.product',
            'items.package.requirements',
        ]);
        $this->actionMessage = __('messages.refund_waiting_approval');
    }

    /**
     * @return Collection<int, \App\Models\OrderItem>
     */
    public function getItemsProperty(): Collection
    {
        return $this->order->items;
    }

    protected function statusLabel(?FulfillmentStatus $status): string
    {
        return match ($status) {
            FulfillmentStatus::Completed => __('messages.delivery_completed'),
            FulfillmentStatus::Failed => __('messages.delivery_failed'),
            FulfillmentStatus::Processing, FulfillmentStatus::Queued => __('messages.delivery_preparing'),
            default => __('messages.delivery_preparing'),
        };
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

    /**
     * @return array<int, array{key: string, value: string}>
     */
    protected function payloadEntries(mixed $payload): array
    {
        $payload = $this->normalizePayload($payload);

        if ($payload === null) {
            return [];
        }

        $values = is_array($payload) ? $payload : ['value' => $payload];
        $entries = [];

        foreach ($values as $key => $value) {
            $keyLabel = is_string($key) ? $key : (string) $key;

            $entries[] = [
                'key' => $keyLabel,
                'value' => $this->stringifyPayloadValue($value),
            ];
        }

        return $entries;
    }

    protected function stringifyPayloadValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        if (is_null($value)) {
            return 'null';
        }

        return (string) $value;
    }

    protected function normalizePayload(mixed $payload): mixed
    {
        if (! is_string($payload)) {
            return $payload;
        }

        $decoded = json_decode($payload, true);

        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array{key: string, label: string, value: string}>
     */
    protected function requirementsEntries(?array $payload, ?OrderItem $item = null): array
    {
        if ($payload === null || $payload === []) {
            return [];
        }

        $entries = [];

        foreach ($payload as $key => $value) {
            $keyString = is_string($key) ? $key : (string) $key;
            $entries[] = [
                'key' => $keyString,
                'label' => OrderRequirementLabels::labelForKey($item, $keyString),
                'value' => $this->stringifyPayloadValue($value),
            ];
        }

        return $entries;
    }
};
?>

<div class="mx-auto w-full max-w-5xl px-3 py-6 sm:px-0 sm:py-10">
    <div class="mb-4 flex items-center">
        <x-back-button :fallback="route('orders.index')" />
    </div>
    <div class="flex flex-col gap-6">
        <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="space-y-1">
                    <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                        {{ __('messages.order_details') }}
                    </flux:heading>
                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                        {{ $order->order_number }}
                    </flux:text>
                </div>
                <div class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ $order->created_at?->format('M d, Y H:i') ?? '—' }}
                </div>
            </div>

            <div class="mt-4 grid gap-3 text-sm text-zinc-600 dark:text-zinc-400 sm:grid-cols-2">
                <div class="flex items-center justify-between gap-2 rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                    <span>{{ __('messages.order') }}</span>
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                        #{{ $order->id }}
                    </span>
                </div>
                <div class="flex items-center justify-between gap-2 rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                    <span>{{ __('messages.payment_status') }}</span>
                    <flux:badge color="{{ match ($order->status) {
                        OrderStatus::Fulfilled => 'green',
                        OrderStatus::Failed, OrderStatus::Cancelled => 'red',
                        OrderStatus::Refunded => 'gray',
                        OrderStatus::Paid => 'blue',
                        default => 'amber',
                    } }}">
                        {{ $this->orderStatusLabel($order->status) }}
                    </flux:badge>
                </div>
                <div class="flex items-center justify-between gap-2 rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                    <span>{{ __('messages.total') }}</span>
                    @if(\App\Models\WebsiteSetting::getPricesVisible())
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100" dir="ltr">
                        {{ $this->formatAmount($order->total, $order->currency) }}
                    </span>
                    @else
                    <span class="font-semibold text-zinc-500 dark:text-zinc-400">—</span>
                    @endif
                </div>
                <div class="flex items-center justify-between gap-2 rounded-xl border border-zinc-100 bg-zinc-50 px-4 py-3 dark:border-zinc-800 dark:bg-zinc-800/60">
                    <span>{{ __('messages.created') }}</span>
                    <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $order->created_at?->format('M d, Y H:i') ?? '—' }}
                    </span>
                </div>
            </div>

        </section>

        <section class="space-y-4">
            @foreach ($this->items as $item)
                @php
                    $fulfillments = $item->fulfillments->sortBy('id')->values();
                    $itemStatus = $item->aggregateFulfillmentStatus($fulfillments);
                    $requirementsEntries = $this->requirementsEntries($item->requirements_payload, $item);
                @endphp

                <div id="item-{{ $item->id }}" class="min-w-0 rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900" wire:key="order-item-{{ $item->id }}">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div class="space-y-1">
                            <div class="text-sm font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $item->product?->name ?? $item->name }}
                            </div>
                            <div class="space-y-1 text-xs text-zinc-500 dark:text-zinc-400">
                                @if (($item->amount_mode ?? \App\Enums\ProductAmountMode::Fixed) === \App\Enums\ProductAmountMode::Custom && $item->requested_amount !== null)
                                    <div class="text-zinc-600 dark:text-zinc-300">
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ __('messages.order_item_purchased_amount') }}:</span>
                                        <span class="ms-0.5 font-medium tabular-nums text-zinc-800 dark:text-zinc-100" dir="ltr">{{ number_format((int) $item->requested_amount) }}</span>
                                        @if ($item->amount_unit_label)
                                            <span class="ms-1">{{ $item->amount_unit_label }}</span>
                                        @endif
                                    </div>
                                @endif
                                <div>{{ __('messages.quantity') }}: {{ $item->quantity }}</div>
                            </div>
                            @if ($item->package?->name)
                                <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                    {{ $item->package->name }}
                                </div>
                            @endif
                        </div>
                        <flux:badge color="{{ $itemStatus === FulfillmentStatus::Completed ? 'green' : ($itemStatus === FulfillmentStatus::Failed ? 'red' : ($itemStatus === FulfillmentStatus::Processing ? 'amber' : 'gray')) }}">
                            {{ $this->statusLabel($itemStatus) }}
                        </flux:badge>
                    </div>

                    <div class="mt-4 grid gap-2 text-xs text-zinc-500 dark:text-zinc-400 sm:grid-cols-3">
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <span>{{ __('messages.unit_price') }}</span>
                            @if(\App\Models\WebsiteSetting::getPricesVisible())
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100" dir="ltr">
                                {{ $this->formatAmount($item->unit_price, $order->currency) }}
                            </span>
                            @else
                            <span class="font-semibold text-zinc-500 dark:text-zinc-400">—</span>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <span>{{ __('messages.line_total') }}</span>
                            @if(\App\Models\WebsiteSetting::getPricesVisible())
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100" dir="ltr">
                                {{ $this->formatAmount($item->line_total, $order->currency) }}
                            </span>
                            @else
                            <span class="font-semibold text-zinc-500 dark:text-zinc-400">—</span>
                            @endif
                        </div>
                        <div class="flex items-center justify-between gap-2 rounded-lg border border-zinc-100 bg-zinc-50 px-3 py-2 dark:border-zinc-800 dark:bg-zinc-800/60">
                            <span>{{ __('messages.payment_status') }}</span>
                            <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                                {{ $this->orderStatusLabel($order->status) }}
                            </span>
                        </div>
                    </div>

                    @if ($requirementsEntries !== [])
                        <div class="mt-4 rounded-xl border border-zinc-100 bg-zinc-50 p-3 text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-300">
                            <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                {{ __('messages.requirements') }}
                            </div>
                            <div class="mt-2 grid gap-2">
                                @foreach ($requirementsEntries as $entry)
                                    <div class="flex flex-wrap items-center justify-between gap-2">
                                        <span class="text-zinc-500 dark:text-zinc-400">{{ $entry['label'] }}</span>
                                        <span class="font-semibold text-zinc-900 dark:text-zinc-100">
                                            {{ $entry['value'] }}
                                        </span>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif

                    <div class="mt-4 space-y-3">
                        @if ($fulfillments->isEmpty())
                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                {{ __('messages.delivery_preparing_hint') }}
                            </flux:text>
                        @else
                            @foreach ($fulfillments as $index => $fulfillment)
                                @php
                                    $payload = data_get($fulfillment->meta, 'delivered_payload');
                                    $payloadEntries = $this->payloadEntries($payload);
                                    $refundStatus = data_get($fulfillment->meta, 'refund.status');
                                    $isRefundPending = $refundStatus === WalletTransaction::STATUS_PENDING;
                                    $isRefundPosted = $refundStatus === WalletTransaction::STATUS_POSTED;
                                    $isRefundRejected = $refundStatus === WalletTransaction::STATUS_REJECTED;
                                    $retryRequested = data_get($fulfillment->meta, 'last_retry_actor') === 'customer'
                                        && (int) data_get($fulfillment->meta, 'retry_count', 0) > 0;
                                    $showActions = $fulfillment->status === FulfillmentStatus::Failed
                                        && ! $isRefundPending
                                        && ! $isRefundPosted;
                                @endphp

                                <div class="min-w-0 rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/60" wire:key="fulfillment-unit-{{ $fulfillment->id }}">
                                    <div class="flex flex-wrap items-center justify-between gap-3">
                                        <div>
                                            <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                {{ __('messages.unit') }} {{ $index + 1 }}
                                            </div>
                                            <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                                #{{ $fulfillment->id }}
                                            </div>
                                        </div>
                                        <div class="flex flex-wrap items-center gap-2">
                                            <flux:badge color="{{ $fulfillment->status === FulfillmentStatus::Completed ? 'green' : ($fulfillment->status === FulfillmentStatus::Failed ? 'red' : 'amber') }}">
                                                {{ $this->statusLabel($fulfillment->status) }}
                                            </flux:badge>
                                            @if ($isRefundPending)
                                                <flux:badge color="amber">{{ __('messages.refund_requested') }}</flux:badge>
                                            @elseif ($isRefundPosted)
                                                <flux:badge color="green">{{ __('messages.refunded') }}</flux:badge>
                                            @elseif ($isRefundRejected)
                                                <flux:badge color="red">{{ __('messages.refund_rejected') }}</flux:badge>
                                            @endif
                                            @if ($retryRequested && $fulfillment->status === FulfillmentStatus::Queued)
                                                <flux:badge color="blue">{{ __('messages.retry_requested') }}</flux:badge>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="mt-3">
                                        @if ($fulfillment->status === FulfillmentStatus::Completed)
                                            @if ($payloadEntries !== [])
                                                <div class="min-w-0 space-y-2">
                                                    <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                                        {{ __('messages.delivery_payload') }}
                                                    </flux:text>
                                                    <div class="grid min-w-0 gap-2 overflow-hidden rounded-xl border border-zinc-200 bg-white p-3 text-xs text-zinc-600 dark:border-zinc-700 dark:bg-zinc-900 dark:text-zinc-200">
                                                        @foreach ($payloadEntries as $entry)
                                                            <div class="flex min-w-0 flex-wrap items-start justify-between gap-2" wire:key="fulfillment-payload-{{ $fulfillment->id }}-{{ $entry['key'] }}">
                                                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                                                    <span class="text-[11px] uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                                        {{ $entry['key'] }}
                                                                    </span>
                                                                    <span class="block max-w-full break-all font-mono text-xs text-zinc-900 dark:text-zinc-100">
                                                                        {{ $entry['value'] }}
                                                                    </span>
                                                                </div>
                                                            </div>
                                                        @endforeach
                                                    </div>
                                                </div>
                                            @else
                                                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                                    {{ __('messages.no_payload') }}
                                                </flux:text>
                                            @endif
                                        @elseif ($fulfillment->status === FulfillmentStatus::Failed)
                                            <div class="space-y-3">
                                                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                                    {{ __('messages.delivery_failed_contact_support') }}
                                                </flux:text>
                                                @if ($isRefundPending)
                                                    <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                                                        {{ __('messages.refund_waiting_approval') }}
                                                    </flux:text>
                                                @elseif ($isRefundPosted)
                                                    <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                                                        {{ __('messages.refund_completed') }}
                                                    </flux:text>
                                                @elseif ($isRefundRejected)
                                                    <flux:text class="text-xs text-zinc-600 dark:text-zinc-400">
                                                        {{ __('messages.refund_rejected') }}
                                                    </flux:text>
                                                @endif
                                                @if ($showActions)
                                                    <div class="flex flex-wrap items-center gap-2">
                                                        <flux:button
                                                            variant="primary"
                                                            size="sm"
                                                            wire:click="requestRefund({{ $fulfillment->id }})"
                                                            wire:loading.attr="disabled"
                                                            wire:target="requestRefund({{ $fulfillment->id }})"
                                                            data-test="fulfillment-request-refund"
                                                        >
                                                            {{ __('messages.refund') }}
                                                        </flux:button>
                                                    </div>
                                                @endif
                                            </div>
                                        @else
                                            <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                                                {{ __('messages.delivery_preparing_hint') }}
                                            </flux:text>
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        @endif
                    </div>
                </div>
            @endforeach
        </section>
    </div>
</div>
