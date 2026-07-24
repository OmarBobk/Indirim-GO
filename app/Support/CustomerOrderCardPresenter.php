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

/**
 * Presentation mapping for customer order list cards.
 * Does not change order, fulfillment, refund, or pricing rules.
 */
final class CustomerOrderCardPresenter
{
    public const FILTER_ALL = CustomerOrderFulfillmentClassifier::ALL;

    public const FILTER_NEEDS_ATTENTION = CustomerOrderFulfillmentClassifier::NEEDS_ATTENTION;

    public const FILTER_IN_PROGRESS = CustomerOrderFulfillmentClassifier::IN_PROGRESS;

    public const FILTER_DELIVERED = CustomerOrderFulfillmentClassifier::DELIVERED;

    public const FILTER_REFUNDED = CustomerOrderFulfillmentClassifier::REFUNDED;

    public function __construct(
        private readonly User $user,
        private readonly bool $pricesVisible,
        private readonly CustomerOrderFulfillmentClassifier $fulfillmentClassifier,
    ) {}

    public static function for(?User $user = null): self
    {
        $resolved = $user ?? auth()->user();

        if ($resolved === null) {
            throw new \RuntimeException('CustomerOrderCardPresenter requires an authenticated user.');
        }

        return new self(
            $resolved,
            WebsiteSetting::getPricesVisible(),
            app(CustomerOrderFulfillmentClassifier::class),
        );
    }

    /**
     * @return array<string, string>
     */
    public function filterOptions(): array
    {
        return [
            self::FILTER_ALL => __('messages.orders_filter_all'),
            self::FILTER_NEEDS_ATTENTION => __('messages.orders_filter_needs_attention'),
            self::FILTER_IN_PROGRESS => __('messages.orders_filter_in_progress'),
            self::FILTER_DELIVERED => __('messages.orders_filter_delivered'),
            self::FILTER_REFUNDED => __('messages.orders_filter_refunded'),
        ];
    }

    public function normalizeFilter(string $filter): string
    {
        return $this->fulfillmentClassifier->normalizeFilter($filter);
    }

    /**
     * @return array{title: string, hint: string, showHomeAction: bool}
     */
    public function emptyState(string $filter, string $search = ''): array
    {
        if (trim($search) !== '') {
            return [
                'title' => __('messages.orders_empty_no_matches'),
                'hint' => __('messages.orders_empty_no_matches_hint'),
                'showHomeAction' => false,
            ];
        }

        return match ($this->normalizeFilter($filter)) {
            self::FILTER_NEEDS_ATTENTION => [
                'title' => __('messages.orders_empty_needs_attention'),
                'hint' => __('messages.orders_empty_needs_attention_hint'),
                'showHomeAction' => false,
            ],
            self::FILTER_IN_PROGRESS => [
                'title' => __('messages.orders_empty_in_progress'),
                'hint' => __('messages.orders_empty_in_progress_hint'),
                'showHomeAction' => false,
            ],
            self::FILTER_DELIVERED => [
                'title' => __('messages.orders_empty_delivered'),
                'hint' => __('messages.orders_empty_delivered_hint'),
                'showHomeAction' => false,
            ],
            self::FILTER_REFUNDED => [
                'title' => __('messages.orders_empty_refunded'),
                'hint' => __('messages.orders_empty_refunded_hint'),
                'showHomeAction' => false,
            ],
            default => [
                'title' => __('messages.no_orders'),
                'hint' => __('messages.no_orders_hint'),
                'showHomeAction' => true,
            ],
        };
    }

    public function needsAttention(Order $order): bool
    {
        return $this->fulfillmentClassifier->needsAttention($order);
    }

    /**
     * @return array{
     *     href: string,
     *     orderNumber: string,
     *     formattedDate: string,
     *     formattedTotal: string,
     *     showPrices: bool,
     *     status: array{label: string, color: string, progress: int},
     *     summary: array{lines: int, units: int},
     *     lines: list<array<string, mixed>>,
     *     refundSummary: array{kind: 'badge', label: string, color: string}|array{kind: 'action', label: string, orderId: int}|null
     * }
     */
    public function present(Order $order): array
    {
        return [
            'href' => route('orders.show', $order->order_number),
            'orderNumber' => (string) $order->order_number,
            'formattedDate' => $order->created_at?->format('M d, Y H:i') ?? '—',
            'formattedTotal' => $this->formatAmount($order->total, (string) $order->currency),
            'showPrices' => $this->pricesVisible,
            'status' => $this->unifiedStatus($order),
            'summary' => [
                'lines' => $order->items->count(),
                'units' => (int) $order->items->sum('quantity'),
            ],
            'lines' => $this->buildLines($order),
            'refundSummary' => $this->refundSummary($order),
        ];
    }

    /**
     * @return array{label: string, color: string, progress: int}
     */
    public function unifiedStatus(Order $order): array
    {
        $fulfillment = $this->fulfillmentSummary($order);

        if (in_array($order->status, [OrderStatus::Cancelled, OrderStatus::Failed], true)) {
            return [
                'label' => $this->orderStatusLabel($order->status),
                'color' => 'red',
                'progress' => 0,
            ];
        }

        if ($fulfillment['color'] === 'red') {
            return [
                'label' => $fulfillment['label'],
                'color' => 'red',
                'progress' => 100,
            ];
        }

        if ($order->status === OrderStatus::Refunded) {
            return [
                'label' => $this->orderStatusLabel($order->status),
                'color' => 'gray',
                'progress' => 100,
            ];
        }

        if ($order->status === OrderStatus::PendingPayment) {
            return [
                'label' => $this->orderStatusLabel($order->status),
                'color' => 'amber',
                'progress' => 25,
            ];
        }

        if ($order->status === OrderStatus::Fulfilled || $fulfillment['color'] === 'green') {
            return [
                'label' => $order->status === OrderStatus::Fulfilled
                    ? $this->orderStatusLabel(OrderStatus::Fulfilled)
                    : $fulfillment['label'],
                'color' => 'green',
                'progress' => 100,
            ];
        }

        if ($order->status === OrderStatus::Processing || $fulfillment['color'] === 'amber') {
            return [
                'label' => $order->status === OrderStatus::Processing
                    ? $this->orderStatusLabel(OrderStatus::Processing)
                    : $fulfillment['label'],
                'color' => 'amber',
                'progress' => 75,
            ];
        }

        if ($order->status === OrderStatus::Paid) {
            return [
                'label' => $this->orderStatusLabel(OrderStatus::Paid),
                'color' => 'blue',
                'progress' => 50,
            ];
        }

        return [
            'label' => $fulfillment['label'],
            'color' => $fulfillment['color'] === 'gray' ? 'amber' : $fulfillment['color'],
            'progress' => 40,
        ];
    }

    /**
     * @return array{label: string, color: string}
     */
    public function fulfillmentSummary(Order $order): array
    {
        $items = $order->items;

        if ($items->isEmpty()) {
            return [
                'label' => __('messages.fulfillment_status_queued'),
                'color' => 'gray',
            ];
        }

        $fulfillments = $items->flatMap(fn ($item) => $item->fulfillments);
        $hasEmpty = $items->contains(fn ($item) => $item->fulfillments->isEmpty());

        if ($fulfillments->isEmpty()) {
            return [
                'label' => __('messages.fulfillment_status_queued'),
                'color' => 'gray',
            ];
        }

        $hasFailed = $fulfillments->contains(fn ($fulfillment) => $fulfillment->status === FulfillmentStatus::Failed);
        $hasProcessing = $fulfillments->contains(fn ($fulfillment) => $fulfillment->status === FulfillmentStatus::Processing);
        $hasQueued = $fulfillments->contains(fn ($fulfillment) => $fulfillment->status === FulfillmentStatus::Queued);
        $allCompleted = $fulfillments->every(fn ($fulfillment) => $fulfillment->status === FulfillmentStatus::Completed);

        if ($hasFailed) {
            return [
                'label' => __('messages.fulfillment_status_failed'),
                'color' => 'red',
            ];
        }

        if ($hasProcessing) {
            return [
                'label' => __('messages.fulfillment_status_processing'),
                'color' => 'amber',
            ];
        }

        if ($hasQueued || $hasEmpty) {
            return [
                'label' => __('messages.fulfillment_status_queued'),
                'color' => 'gray',
            ];
        }

        if ($allCompleted) {
            return [
                'label' => __('messages.delivery_completed'),
                'color' => 'green',
            ];
        }

        return [
            'label' => __('messages.fulfillment_status_queued'),
            'color' => 'gray',
        ];
    }

    /**
     * @return array{kind: 'badge', label: string, color: string}|array{kind: 'action', label: string, orderId: int}|null
     */
    public function refundSummary(Order $order): ?array
    {
        $failed = $order->items
            ->flatMap(fn (OrderItem $item) => $item->fulfillments)
            ->filter(fn ($fulfillment) => $fulfillment->status === FulfillmentStatus::Failed);

        if ($failed->isEmpty()) {
            return null;
        }

        $statuses = $failed->map(fn (Fulfillment $fulfillment) => $this->fulfillmentRefundStatus($fulfillment));

        if ($statuses->every(fn ($status) => $status === WalletTransaction::STATUS_POSTED)) {
            return [
                'kind' => 'badge',
                'label' => __('messages.refunded'),
                'color' => 'green',
            ];
        }

        if ($statuses->contains(fn ($status) => $status === WalletTransaction::STATUS_PENDING)) {
            return [
                'kind' => 'badge',
                'label' => __('messages.refund_requested'),
                'color' => 'amber',
            ];
        }

        return [
            'kind' => 'action',
            'label' => __('messages.refund'),
            'orderId' => $order->id,
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function buildLines(Order $order): array
    {
        $out = [];

        foreach ($order->items as $item) {
            $productName = $item->name;
            $packageName = $item->package?->name;
            $showPackage = $packageName !== null && $packageName !== $productName;

            $idMetaLabel = OrderRequirementLabels::fallbackLabel('id');
            $playerId = null;
            foreach ($item->requirements_payload ?? [] as $key => $value) {
                if (strtolower((string) $key) === 'id') {
                    $playerId = $this->stringifyPayloadValue($value);
                    $idMetaLabel = OrderRequirementLabels::labelForKey($item, (string) $key);
                    break;
                }
            }

            $metaParts = [];
            $metaParts[] = __('messages.quantity').' '.$item->quantity;
            if ($this->pricesVisible) {
                $metaParts[] = $this->formatAmount($item->unit_price, (string) $order->currency).' / '.__('messages.unit');
            }
            if ($playerId !== null) {
                $metaParts[] = $idMetaLabel.': '.$playerId;
            }

            $showLinePrice = $this->shouldShowLineItemPrice($order, $item);
            $lineTotalFormatted = ($this->pricesVisible && $showLinePrice)
                ? $this->formatAmount($item->line_total, (string) $order->currency)
                : null;

            $customAmount = null;
            if (($item->amount_mode ?? ProductAmountMode::Fixed) === ProductAmountMode::Custom && $item->requested_amount !== null) {
                $customAmount = __('messages.order_item_purchased_amount').': '.number_format((int) $item->requested_amount)
                    .(($item->amount_unit_label !== null && $item->amount_unit_label !== '') ? ' '.$item->amount_unit_label : '');
            }

            $fulfillments = $item->fulfillments->sortBy('id')->values();
            $units = [];
            if ($item->quantity > 1 && $fulfillments->isNotEmpty()) {
                foreach ($fulfillments as $index => $_) {
                    $units[] = [
                        'meta' => __('messages.order_id').': #'.$item->id.'U'.($index + 1).' · '.__('messages.unit').' '.($index + 1).' / '.$item->quantity,
                    ];
                }
            }

            $out[] = [
                'title' => $productName,
                'subtitle' => $showPackage ? $packageName : null,
                'meta' => implode(' · ', $metaParts),
                'custom_amount' => $customAmount,
                'line_total' => $lineTotalFormatted,
                'image' => $item->package?->image,
                'expandable_units' => $units !== [],
                'units' => $units,
            ];
        }

        return $out;
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

    public function formatAmount(float|string $amount, string $currency): string
    {
        return FrontendMoney::for($this->user)->format($amount, $currency, 2);
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

    public function shouldShowLineItemPrice(Order $order, OrderItem $item): bool
    {
        if ($order->items->count() !== 1) {
            return true;
        }

        return $item->quantity !== 1;
    }

    protected function fulfillmentRefundStatus(Fulfillment $fulfillment): ?string
    {
        if (array_key_exists('refund_status', $fulfillment->getAttributes())) {
            $status = $fulfillment->getAttribute('refund_status');

            return is_string($status) ? $status : null;
        }

        $status = data_get($fulfillment->meta, 'refund.status');

        return is_string($status) ? $status : null;
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
