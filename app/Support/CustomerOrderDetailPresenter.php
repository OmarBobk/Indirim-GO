<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\ProductAmountMode;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Models\WebsiteSetting;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Presentation mapping for the customer Order Details workspace.
 * Does not query, authorize, mutate, or change refund/retry rules.
 */
final class CustomerOrderDetailPresenter
{
    public function __construct(
        private readonly User $user,
        private readonly bool $pricesVisible,
        private readonly FrontendMoney $money,
        private readonly CustomerOrderFulfillmentClassifier $fulfillmentClassifier,
    ) {}

    public static function for(?User $user = null): self
    {
        $resolved = $user ?? auth()->user();

        if ($resolved === null) {
            throw new \RuntimeException('CustomerOrderDetailPresenter requires an authenticated user.');
        }

        return new self(
            $resolved,
            WebsiteSetting::getPricesVisible(),
            FrontendMoney::for($resolved),
            app(CustomerOrderFulfillmentClassifier::class),
        );
    }

    /**
     * @return array{
     *     orderId: int,
     *     orderNumber: string,
     *     formattedDate: string,
     *     createdLabel: string,
     *     showPrices: bool,
     *     formattedTotal: string|null,
     *     paymentStatus: array{label: string, color: string},
     *     classification: string|null,
     *     items: list<array<string, mixed>>
     * }
     */
    public function present(Order $order): array
    {
        $formattedDate = $order->created_at?->format('M d, Y H:i') ?? '—';

        return [
            'orderId' => (int) $order->id,
            'orderNumber' => (string) $order->order_number,
            'formattedDate' => $formattedDate,
            'createdLabel' => $formattedDate,
            'showPrices' => $this->pricesVisible,
            'formattedTotal' => $this->pricesVisible
                ? $this->formatAmount($order->total, (string) $order->currency)
                : null,
            'paymentStatus' => [
                'label' => $this->orderStatusLabel($order->status),
                'color' => $this->orderStatusColor($order->status),
            ],
            'classification' => $this->safeClassification($order),
            'items' => $this->presentItems($order),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function presentItems(Order $order): array
    {
        $out = [];

        foreach ($order->items as $item) {
            $fulfillments = $item->fulfillments->sortBy('id')->values();
            $itemStatus = $item->aggregateFulfillmentStatus($fulfillments);

            $out[] = [
                'id' => (int) $item->id,
                'name' => (string) $item->name,
                'packageName' => $item->package?->name,
                'quantity' => (int) $item->quantity,
                'customAmount' => $this->customAmount($item),
                'formattedUnitPrice' => $this->pricesVisible
                    ? $this->formatAmount($item->unit_price, (string) $order->currency)
                    : null,
                'formattedLineTotal' => $this->pricesVisible
                    ? $this->formatAmount($item->line_total, (string) $order->currency)
                    : null,
                'paymentStatusLabel' => $this->orderStatusLabel($order->status),
                'status' => [
                    'label' => $this->fulfillmentStatusLabel($itemStatus),
                    'color' => $this->itemStatusColor($itemStatus),
                ],
                'requirements' => $this->requirementsEntries($item->requirements_payload, $item),
                'units' => $this->presentUnits($order, $fulfillments),
                ...$this->orderAgainPresentation($item),
            ];
        }

        return $out;
    }

    /**
     * @return array{
     *     showOrderAgain: bool,
     *     orderAgainPackageId: int|null,
     *     orderAgainProductId: int|null,
     *     orderAgainLabel: string
     * }
     */
    private function orderAgainPresentation(OrderItem $item): array
    {
        $package = $item->relationLoaded('package') ? $item->package : null;
        $product = $item->relationLoaded('product') ? $item->product : null;

        $show = $package !== null
            && $product !== null
            && $item->package_id !== null
            && $item->product_id !== null
            && (bool) $package->is_active
            && (bool) $product->is_active
            && (int) $product->package_id === (int) $item->package_id;

        return [
            'showOrderAgain' => $show,
            'orderAgainPackageId' => $show ? (int) $item->package_id : null,
            'orderAgainProductId' => $show ? (int) $item->product_id : null,
            'orderAgainLabel' => __('messages.order_again'),
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Fulfillment>  $fulfillments
     * @return list<array<string, mixed>>
     */
    private function presentUnits(Order $order, $fulfillments): array
    {
        $out = [];

        foreach ($fulfillments as $index => $fulfillment) {
            $refundStatus = data_get($fulfillment->meta, 'refund.status');
            $isRefundPending = $refundStatus === WalletTransaction::STATUS_PENDING;
            $isRefundPosted = $refundStatus === WalletTransaction::STATUS_POSTED;
            $isRefundRejected = $refundStatus === WalletTransaction::STATUS_REJECTED;
            $retryRequested = data_get($fulfillment->meta, 'last_retry_actor') === 'customer'
                && (int) data_get($fulfillment->meta, 'retry_count', 0) > 0;
            $showRefundAction = $fulfillment->status === FulfillmentStatus::Failed
                && ! $isRefundPending
                && ! $isRefundPosted;

            $payloadEntries = $fulfillment->status === FulfillmentStatus::Completed
                ? CustomerDeliveredPayload::entries(data_get($fulfillment->meta, 'delivered_payload'))
                : [];

            $out[] = [
                'id' => (int) $fulfillment->id,
                'index' => $index + 1,
                'status' => [
                    'label' => $this->fulfillmentStatusLabel($fulfillment->status),
                    'color' => $this->unitStatusColor($fulfillment->status),
                    'value' => $fulfillment->status?->value,
                ],
                'isCompleted' => $fulfillment->status === FulfillmentStatus::Completed,
                'isFailed' => $fulfillment->status === FulfillmentStatus::Failed,
                'isPreparing' => ! in_array($fulfillment->status, [FulfillmentStatus::Completed, FulfillmentStatus::Failed], true),
                'payloadEntries' => $payloadEntries,
                'hasPayload' => $payloadEntries !== [],
                'isRefundPending' => $isRefundPending,
                'isRefundPosted' => $isRefundPosted,
                'isRefundRejected' => $isRefundRejected,
                'showRetryRequestedBadge' => $retryRequested && $fulfillment->status === FulfillmentStatus::Queued,
                'showRefundAction' => $showRefundAction,
                'timeline' => $this->presentTimeline($order, $fulfillment),
            ];
        }

        return $out;
    }

    /**
     * Compact customer delivery timeline from already-loaded order/fulfillment fields.
     * Omits events that cannot be derived safely. Does not query or invent timestamps.
     *
     * @return list<array{key: string, label: string, date: string|null, completed: bool, current: bool, tone: string}>
     */
    private function presentTimeline(Order $order, Fulfillment $fulfillment): array
    {
        /** @var list<array{key: string, label: string, at: CarbonInterface|null, tone: string}> $raw */
        $raw = [];

        if ($order->created_at !== null) {
            $raw[] = [
                'key' => 'order_placed',
                'label' => __('messages.order_detail_timeline_order_placed'),
                'at' => $order->created_at,
                'tone' => 'zinc',
            ];
        }

        if ($order->paid_at !== null) {
            $raw[] = [
                'key' => 'payment_completed',
                'label' => __('messages.order_detail_timeline_payment_completed'),
                'at' => $order->paid_at,
                'tone' => 'blue',
            ];
        }

        if ($fulfillment->created_at !== null) {
            $raw[] = [
                'key' => 'delivery_started',
                'label' => __('messages.order_detail_timeline_delivery_started'),
                'at' => $fulfillment->created_at,
                'tone' => 'amber',
            ];
        }

        if ($fulfillment->processed_at !== null) {
            $raw[] = [
                'key' => 'delivery_processing',
                'label' => __('messages.order_detail_timeline_delivery_processing'),
                'at' => $fulfillment->processed_at,
                'tone' => 'amber',
            ];
        } elseif ($fulfillment->status === FulfillmentStatus::Processing) {
            $raw[] = [
                'key' => 'delivery_processing',
                'label' => __('messages.order_detail_timeline_delivery_processing'),
                'at' => null,
                'tone' => 'amber',
            ];
        }

        if ($fulfillment->status === FulfillmentStatus::Completed || $fulfillment->completed_at !== null) {
            $raw[] = [
                'key' => 'delivery_completed',
                'label' => __('messages.order_detail_timeline_delivery_completed'),
                'at' => $fulfillment->completed_at,
                'tone' => 'green',
            ];
        }

        if ($fulfillment->status === FulfillmentStatus::Failed) {
            $raw[] = [
                'key' => 'delivery_failed',
                'label' => __('messages.order_detail_timeline_delivery_failed'),
                'at' => null,
                'tone' => 'red',
            ];
        }

        $lastRetryAt = $this->parseMetaTimestamp(data_get($fulfillment->meta, 'last_retry_at'));
        if ($lastRetryAt !== null && data_get($fulfillment->meta, 'last_retry_actor') === 'customer') {
            $raw[] = [
                'key' => 'retry_requested',
                'label' => __('messages.order_detail_timeline_retry_requested'),
                'at' => $lastRetryAt,
                'tone' => 'blue',
            ];
        }

        $refundStatus = data_get($fulfillment->meta, 'refund.status');
        $refundRequestedAt = $this->parseMetaTimestamp(data_get($fulfillment->meta, 'refund.requested_at'));
        if (in_array($refundStatus, [
            WalletTransaction::STATUS_PENDING,
            WalletTransaction::STATUS_POSTED,
            WalletTransaction::STATUS_REJECTED,
        ], true)) {
            $raw[] = [
                'key' => 'refund_requested',
                'label' => __('messages.order_detail_timeline_refund_requested'),
                'at' => $refundRequestedAt,
                'tone' => 'amber',
            ];
        }

        if ($refundStatus === WalletTransaction::STATUS_POSTED) {
            $raw[] = [
                'key' => 'refund_completed',
                'label' => __('messages.order_detail_timeline_refund_completed'),
                'at' => $this->parseMetaTimestamp(data_get($fulfillment->meta, 'refund.approved_at')),
                'tone' => 'green',
            ];
        }

        if ($raw === []) {
            return [];
        }

        $lastIndex = count($raw) - 1;

        $events = [];
        foreach ($raw as $index => $event) {
            $events[] = [
                'key' => $event['key'],
                'label' => $event['label'],
                'date' => $event['at']?->format('M d, Y H:i'),
                'completed' => $index < $lastIndex,
                'current' => $index === $lastIndex,
                'tone' => $event['tone'],
            ];
        }

        return $events;
    }

    private function parseMetaTimestamp(mixed $value): ?CarbonInterface
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{label: string, amount: string, unitLabel: string|null}|null
     */
    private function customAmount(OrderItem $item): ?array
    {
        if (($item->amount_mode ?? ProductAmountMode::Fixed) !== ProductAmountMode::Custom || $item->requested_amount === null) {
            return null;
        }

        return [
            'label' => __('messages.order_item_purchased_amount'),
            'amount' => number_format((int) $item->requested_amount),
            'unitLabel' => $item->amount_unit_label !== null && $item->amount_unit_label !== ''
                ? (string) $item->amount_unit_label
                : null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<array{key: string, label: string, value: string}>
     */
    public function requirementsEntries(?array $payload, ?OrderItem $item = null): array
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

    public function orderStatusLabel(OrderStatus $status): string
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

    public function fulfillmentStatusLabel(?FulfillmentStatus $status): string
    {
        return match ($status) {
            FulfillmentStatus::Completed => __('messages.delivery_completed'),
            FulfillmentStatus::Failed => __('messages.delivery_failed'),
            FulfillmentStatus::Processing, FulfillmentStatus::Queued => __('messages.delivery_preparing'),
            default => __('messages.delivery_preparing'),
        };
    }

    public function formatAmount(float|string $amount, string $currency): string
    {
        return $this->money->format($amount, $currency, 2);
    }

    private function orderStatusColor(OrderStatus $status): string
    {
        return match ($status) {
            OrderStatus::Fulfilled => 'green',
            OrderStatus::Failed, OrderStatus::Cancelled => 'red',
            OrderStatus::Refunded => 'gray',
            OrderStatus::Paid => 'blue',
            default => 'amber',
        };
    }

    private function itemStatusColor(?FulfillmentStatus $status): string
    {
        return match ($status) {
            FulfillmentStatus::Completed => 'green',
            FulfillmentStatus::Failed => 'red',
            FulfillmentStatus::Processing => 'amber',
            default => 'gray',
        };
    }

    private function unitStatusColor(?FulfillmentStatus $status): string
    {
        return match ($status) {
            FulfillmentStatus::Completed => 'green',
            FulfillmentStatus::Failed => 'red',
            default => 'amber',
        };
    }

    private function safeClassification(Order $order): ?string
    {
        if (! is_string($order->getAttribute(CustomerOrderFulfillmentClassifier::ATTRIBUTE))) {
            return null;
        }

        return $this->fulfillmentClassifier->classification($order);
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
}
