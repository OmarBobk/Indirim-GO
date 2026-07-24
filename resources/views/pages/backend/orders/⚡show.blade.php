<?php

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WalletTransaction;
use App\Support\OrderRequirementLabels;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Component;

new class extends Component
{
    public Order $order;
    public function mount(Order $order): void
    {
        $this->authorize('view', $order);

        $this->order = $order->load([
            'user',
            'items.fulfillments',
            'items.package.requirements',
        ]);
    }

    /**
     * @return Collection<int, \App\Models\OrderItem>
     */
    public function getItemsProperty(): Collection
    {
        return $this->order->items;
    }

    public function getPaymentTransactionProperty(): ?WalletTransaction
    {
        return WalletTransaction::query()
            ->where('reference_type', Order::class)
            ->where('reference_id', $this->order->id)
            ->where('type', WalletTransactionType::Purchase->value)
            ->latest('created_at')
            ->first();
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

    protected function fulfillmentStatusLabel(?FulfillmentStatus $status): string
    {
        if ($status === null) {
            return __('messages.fulfillment_status_queued');
        }

        return __('messages.fulfillment_status_'.$status->value);
    }

    protected function fulfillmentStatusColor(?FulfillmentStatus $status): string
    {
        return match ($status) {
            FulfillmentStatus::Completed => 'green',
            FulfillmentStatus::Failed => 'red',
            FulfillmentStatus::Processing => 'amber',
            default => 'gray',
        };
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

    /**
     * @param  array<string, mixed>|null  $payload
     * @return array<int, array{label: string, value: string}>
     */
    protected function requirementsEntries(?array $payload, ?OrderItem $item = null): array
    {
        if ($payload === null || $payload === []) {
            return [];
        }

        $entries = [];

        foreach ($payload as $key => $value) {
            $keyLabel = is_string($key) ? $key : (string) $key;

            $entries[] = [
                'label' => OrderRequirementLabels::labelForKey($item, $keyLabel),
                'value' => $this->stringifyPayloadValue($value),
            ];
        }

        return $entries;
    }

    public function render(): View
    {
        return $this->view()->title(__('messages.order_details'));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <x-back-button :fallback="route('admin.orders.index')" />
            <div>
                <flux:heading size="lg" class="text-zinc-900 dark:text-zinc-100">
                    {{ __('messages.order_details') }}
                </flux:heading>
                <flux:text class="text-sm text-zinc-600 dark:text-zinc-400">
                    {{ $order->order_number }}
                </flux:text>
            </div>
        </div>
        <flux:badge color="{{ $this->orderStatusColor($order->status) }}">
            {{ $this->orderStatusLabel($order->status) }}
        </flux:badge>
    </div>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/60">
                <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    {{ __('messages.order_details') }}
                </div>
                <div class="mt-2 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <div>{{ __('messages.order_number') }}: {{ $order->order_number }}</div>
                    <div>{{ __('messages.username') }}: {{ $order->user?->username ?? '—' }}</div>
                    <div>{{ __('messages.email') }}: {{ $order->user?->email ?? '—' }}</div>
                    <div>{{ __('messages.created') }}: {{ $order->created_at?->format('M d, Y H:i') ?? '—' }}</div>
                    <div>{{ __('messages.paid_at') }}: {{ $order->paid_at?->format('M d, Y H:i') ?? '—' }}</div>
                </div>
            </div>

            <div class="rounded-xl border border-zinc-100 bg-zinc-50 p-4 dark:border-zinc-800 dark:bg-zinc-800/60">
                <div class="text-xs uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                    {{ __('messages.total') }}
                </div>
                <div class="mt-2 space-y-2 text-sm text-zinc-700 dark:text-zinc-300">
                    <div>{{ __('messages.subtotal') }}: {{ $order->subtotal }} {{ $order->currency }}</div>
                    <div>{{ __('messages.fee') }}: {{ $order->fee }} {{ $order->currency }}</div>
                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">
                        {{ $order->total }} {{ $order->currency }}
                    </div>
                </div>
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <div class="flex items-center justify-between gap-3">
            <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
                {{ __('messages.wallet_transactions') }}
            </flux:heading>
        </div>
        <div class="mt-4 rounded-xl border border-zinc-100 bg-zinc-50 p-4 text-sm text-zinc-700 dark:border-zinc-800 dark:bg-zinc-800/60 dark:text-zinc-300">
            @if ($this->paymentTransaction)
                <div class="grid gap-2 sm:grid-cols-2">
                    <div>{{ __('messages.amount') }}: {{ $this->paymentTransaction->amount }} {{ $order->currency }}</div>
                    <div>{{ __('messages.status') }}: {{ __('messages.'.$this->paymentTransaction->status) }}</div>
                    <div>{{ __('messages.created') }}: {{ $this->paymentTransaction->created_at?->format('M d, Y H:i') ?? '—' }}</div>
                    <div>{{ __('messages.reference') }}: #{{ $this->paymentTransaction->id }}</div>
                </div>
            @else
                <div class="text-zinc-500 dark:text-zinc-400">{{ __('messages.no_details') }}</div>
            @endif
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
            {{ __('messages.items') }}
        </flux:heading>
        <div class="mt-4 overflow-hidden rounded-2xl border border-zinc-100 bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-zinc-100 text-sm dark:divide-zinc-800">
                    <thead class="bg-zinc-50 text-xs uppercase tracking-wide text-zinc-500 dark:bg-zinc-800/60 dark:text-zinc-400">
                        <tr>
                            <th class="px-5 py-3 text-start font-semibold">{{ __('messages.item') }}</th>
                            <th class="px-5 py-3 text-start font-semibold">{{ __('messages.quantity') }}</th>
                            <th class="px-5 py-3 text-start font-semibold">{{ __('messages.unit_price') }}</th>
                            <th class="px-5 py-3 text-start font-semibold">{{ __('messages.line_total') }}</th>
                            <th class="px-5 py-3 text-start font-semibold">{{ __('messages.fulfillment_summary') }}</th>
                            <th class="px-5 py-3 text-end font-semibold">{{ __('messages.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-zinc-100 dark:divide-zinc-800">
                        @foreach ($this->items as $item)
                            @php
                                $requirementsEntries = $this->requirementsEntries($item->requirements_payload, $item);
                                $itemStatus = $item->aggregateFulfillmentStatus($item->fulfillments);
                            @endphp
                            <tr class="transition hover:bg-zinc-50 dark:hover:bg-zinc-800/60" wire:key="admin-order-item-{{ $item->id }}">
                                <td class="px-5 py-4">
                                    <div class="font-semibold text-zinc-900 dark:text-zinc-100">
                                        {{ $item->name }}
                                    </div>
                                    <div class="text-xs text-zinc-500 dark:text-zinc-400">
                                        #{{ $item->id }}
                                    </div>
                                </td>
                                <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300">
                                    {{ $item->quantity }}
                                </td>
                                <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300" dir="ltr">
                                    {{ $item->unit_price }} {{ $order->currency }}
                                </td>
                                <td class="px-5 py-4 text-zinc-600 dark:text-zinc-300" dir="ltr">
                                    {{ $item->line_total }} {{ $order->currency }}
                                </td>
                                <td class="px-5 py-4">
                                    <flux:badge color="{{ $this->fulfillmentStatusColor($itemStatus) }}">
                                        {{ $this->fulfillmentStatusLabel($itemStatus) }}
                                    </flux:badge>
                                </td>
                                <td class="px-5 py-4 text-end">
                                    @if ($item->fulfillments->isNotEmpty())
                                        <a
                                            href="{{ route('fulfillments', ['search' => $item->order->order_number]) }}"
                                            wire:navigate
                                            class="text-sm font-semibold text-zinc-900 hover:underline dark:text-zinc-100"
                                        >
                                            {{ __('messages.view_fulfillment') }} ({{ $item->fulfillments->count() }})
                                        </a>
                                    @else
                                        <span class="text-xs text-zinc-500 dark:text-zinc-400">{{ __('messages.no_details') }}</span>
                                    @endif
                                </td>
                            </tr>
                            @if ($requirementsEntries !== [])
                                <tr class="bg-zinc-50/70 dark:bg-zinc-900/70" wire:key="admin-order-item-{{ $item->id }}-requirements">
                                    <td colspan="6" class="px-5 pb-5">
                                        <div class="rounded-xl border border-zinc-100 bg-white p-3 text-xs text-zinc-600 dark:border-zinc-800 dark:bg-zinc-900 dark:text-zinc-300">
                                            <div class="text-[11px] font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400">
                                                {{ __('messages.requirements') }}
                                            </div>
                                            <div class="mt-2 grid gap-2 sm:grid-cols-2">
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
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
    </section>

    <section class="rounded-2xl border border-zinc-200 bg-white p-5 shadow-sm dark:border-zinc-700 dark:bg-zinc-900">
        <flux:heading size="sm" class="text-zinc-900 dark:text-zinc-100">
            {{ __('messages.refund_requests') }}
        </flux:heading>
        <flux:text class="mt-3 text-sm text-zinc-600 dark:text-zinc-400">
            {{ __('messages.refunds_managed_hint') }}
            <a
                href="{{ route('refunds') }}"
                wire:navigate
                class="font-semibold text-zinc-900 hover:underline dark:text-zinc-100"
            >
                {{ __('messages.refund_requests') }}
            </a>
        </flux:text>
    </section>

    <x-timeline :entity="$order" />
</div>
