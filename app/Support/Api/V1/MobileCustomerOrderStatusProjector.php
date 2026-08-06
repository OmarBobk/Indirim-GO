<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Support\CustomerOrderFulfillmentClassifier;
use Illuminate\Support\Collection;

/**
 * Shared customer-safe payment / fulfillment / customer_state projection for mobile orders.
 *
 * Delivery progress is derived from fulfillment rows, never from orders.status alone.
 */
final class MobileCustomerOrderStatusProjector
{
    public const PAYMENT_STATUSES = [
        'pending_payment',
        'paid',
        'processing',
        'fulfilled',
        'failed',
        'refunded',
        'cancelled',
    ];

    public const FULFILLMENT_STATUSES = [
        'pending',
        'queued',
        'processing',
        'completed',
        'failed',
        'cancelled',
    ];

    public const CUSTOMER_STATES = [
        CustomerOrderFulfillmentClassifier::NEEDS_ATTENTION,
        CustomerOrderFulfillmentClassifier::IN_PROGRESS,
        CustomerOrderFulfillmentClassifier::DELIVERED,
        CustomerOrderFulfillmentClassifier::REFUNDED,
        CustomerOrderFulfillmentClassifier::OTHER,
    ];

    public function __construct(
        private readonly CustomerOrderFulfillmentClassifier $classifier,
    ) {}

    /**
     * @return array{
     *     payment_status: string,
     *     fulfillment_status: string,
     *     customer_state: string,
     *     fulfillment_summary: array{
     *         total: int,
     *         queued: int,
     *         processing: int,
     *         completed: int,
     *         failed: int,
     *         cancelled: int
     *     }
     * }
     */
    public function project(Order $order): array
    {
        $fulfillments = $this->collectFulfillments($order);
        $summary = $this->summarize($fulfillments);

        return [
            'payment_status' => $this->paymentStatus($order),
            'fulfillment_status' => $this->fulfillmentStatus($fulfillments),
            'customer_state' => $this->customerState($order),
            'fulfillment_summary' => $summary,
        ];
    }

    public function paymentStatus(Order $order): string
    {
        $status = $order->status instanceof OrderStatus
            ? $order->status
            : OrderStatus::from((string) $order->status);

        return match ($status) {
            OrderStatus::PendingPayment => 'pending_payment',
            OrderStatus::Paid => 'paid',
            OrderStatus::Processing, OrderStatus::Fulfilled => 'paid',
            OrderStatus::Failed => 'failed',
            OrderStatus::Refunded => 'refunded',
            OrderStatus::Cancelled => 'cancelled',
        };
    }

    /**
     * @param  Collection<int, Fulfillment>  $fulfillments
     */
    public function fulfillmentStatus(Collection $fulfillments): string
    {
        if ($fulfillments->isEmpty()) {
            return 'pending';
        }

        $hasQueued = $fulfillments->contains(
            fn (Fulfillment $fulfillment): bool => $fulfillment->status === FulfillmentStatus::Queued
        );
        $hasProcessing = $fulfillments->contains(
            fn (Fulfillment $fulfillment): bool => $fulfillment->status === FulfillmentStatus::Processing
        );

        // Unfinished work keeps the order pollable even when some units failed.
        if ($hasQueued || $hasProcessing) {
            if ($hasProcessing) {
                return 'processing';
            }

            if ($fulfillments->every(
                fn (Fulfillment $fulfillment): bool => $fulfillment->status === FulfillmentStatus::Queued
            )) {
                return 'queued';
            }

            return 'processing';
        }

        $hasFailed = $fulfillments->contains(
            fn (Fulfillment $fulfillment): bool => $fulfillment->status === FulfillmentStatus::Failed
        );
        if ($hasFailed) {
            return 'failed';
        }

        $hasCancelled = $fulfillments->contains(
            fn (Fulfillment $fulfillment): bool => $fulfillment->status === FulfillmentStatus::Cancelled
        );
        if ($hasCancelled) {
            return 'cancelled';
        }

        if ($fulfillments->every(
            fn (Fulfillment $fulfillment): bool => $fulfillment->status === FulfillmentStatus::Completed
        )) {
            return 'completed';
        }

        return 'pending';
    }

    public function customerState(Order $order): string
    {
        $attribute = CustomerOrderFulfillmentClassifier::ATTRIBUTE;

        if (! is_string($order->getAttribute($attribute))) {
            $classified = Order::query()->whereKey($order->getKey());
            $this->classifier->selectClassification($classified);
            $row = $classified->first();

            if ($row !== null) {
                $order->setAttribute($attribute, $row->getAttribute($attribute));
            }
        }

        return $this->classifier->classification($order);
    }

    /**
     * @param  Collection<int, Fulfillment>  $fulfillments
     * @return array{
     *     total: int,
     *     queued: int,
     *     processing: int,
     *     completed: int,
     *     failed: int,
     *     cancelled: int
     * }
     */
    public function summarize(Collection $fulfillments): array
    {
        $counts = [
            'total' => $fulfillments->count(),
            'queued' => 0,
            'processing' => 0,
            'completed' => 0,
            'failed' => 0,
            'cancelled' => 0,
        ];

        foreach ($fulfillments as $fulfillment) {
            $status = $fulfillment->status instanceof FulfillmentStatus
                ? $fulfillment->status
                : FulfillmentStatus::tryFrom((string) $fulfillment->status);

            match ($status) {
                FulfillmentStatus::Queued => $counts['queued']++,
                FulfillmentStatus::Processing => $counts['processing']++,
                FulfillmentStatus::Completed => $counts['completed']++,
                FulfillmentStatus::Failed => $counts['failed']++,
                FulfillmentStatus::Cancelled => $counts['cancelled']++,
                default => null,
            };
        }

        return $counts;
    }

    /**
     * @return Collection<int, Fulfillment>
     */
    public function collectFulfillments(Order $order): Collection
    {
        $order->loadMissing(['items.fulfillments']);

        return $order->items
            ->flatMap(fn ($item) => $item->fulfillments)
            ->values();
    }
}
