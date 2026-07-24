<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Canonical customer-facing order classification.
 *
 * Precedence: payment/order failure, actionable fulfillment failure, refunded,
 * explicitly fulfilled, fully completed, cancelled order, then in progress.
 * The selected SQL value is consumed directly by filters and presentation.
 */
final class CustomerOrderFulfillmentClassifier
{
    public const ATTRIBUTE = 'customer_fulfillment_classification';

    public const ALL = 'all';

    public const NEEDS_ATTENTION = 'needs_attention';

    public const IN_PROGRESS = 'in_progress';

    public const DELIVERED = 'delivered';

    public const REFUNDED = 'refunded';

    public const OTHER = 'other';

    /**
     * Attach the canonical classification to every selected order.
     */
    public function selectClassification(Builder $query): Builder
    {
        [$sql, $bindings] = $this->expression();

        if ($query->getQuery()->columns === null) {
            $query->select('orders.*');
        }

        return $query->selectRaw($sql.' as '.self::ATTRIBUTE, $bindings);
    }

    public function applyFilter(Builder $query, string $classification): Builder
    {
        $classification = $this->normalizeFilter($classification);

        if ($classification === self::ALL) {
            return $query;
        }

        [$sql, $bindings] = $this->expression();

        return $query->whereRaw('('.$sql.') = ?', [...$bindings, $classification]);
    }

    public function normalizeFilter(string $filter): string
    {
        return in_array($filter, [self::ALL, ...$this->filterableClassifications()], true)
            ? $filter
            : self::ALL;
    }

    public function prioritizeNeedsAttention(Builder $query): Builder
    {
        return $query->orderByRaw(
            'CASE WHEN '.self::ATTRIBUTE.' = ? THEN 0 ELSE 1 END',
            [self::NEEDS_ATTENTION],
        );
    }

    public function classification(Order $order): string
    {
        $classification = $order->getAttribute(self::ATTRIBUTE);

        if (! is_string($classification) || ! in_array($classification, $this->classifications(), true)) {
            throw new LogicException('Customer order fulfillment classification was not selected.');
        }

        return $classification;
    }

    public function needsAttention(Order $order): bool
    {
        return $this->classification($order) === self::NEEDS_ATTENTION;
    }

    /**
     * @return list<string>
     */
    private function classifications(): array
    {
        return [
            self::NEEDS_ATTENTION,
            self::IN_PROGRESS,
            self::DELIVERED,
            self::REFUNDED,
            self::OTHER,
        ];
    }

    /**
     * @return list<string>
     */
    private function filterableClassifications(): array
    {
        return [
            self::NEEDS_ATTENTION,
            self::IN_PROGRESS,
            self::DELIVERED,
            self::REFUNDED,
        ];
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function expression(): array
    {
        [$hasMissingFulfillmentSql, $hasMissingFulfillmentBindings] = $this->existsExpression(
            OrderItem::query()
                ->whereColumn('order_items.order_id', 'orders.id')
                ->whereDoesntHave('fulfillments')
        );
        [$hasFailedSql, $hasFailedBindings] = $this->existsExpression(
            $this->failedFulfillmentsQuery()
        );
        [$hasPendingFailedRefundSql, $hasPendingFailedRefundBindings] = $this->existsExpression(
            $this->failedFulfillmentsQuery()
                ->where('meta->refund->status', WalletTransaction::STATUS_PENDING)
        );
        [$hasNonPostedFailedRefundSql, $hasNonPostedFailedRefundBindings] = $this->existsExpression(
            $this->failedFulfillmentsQuery()
                ->where(function (Builder $query): void {
                    $query
                        ->whereNull('meta->refund->status')
                        ->orWhere('meta->refund->status', '!=', WalletTransaction::STATUS_POSTED);
                })
        );
        [$hasFulfillmentSql, $hasFulfillmentBindings] = $this->existsExpression(
            Fulfillment::query()->whereColumn('fulfillments.order_id', 'orders.id')
        );
        [$hasNonCompletedFulfillmentSql, $hasNonCompletedFulfillmentBindings] = $this->existsExpression(
            Fulfillment::query()
                ->whereColumn('fulfillments.order_id', 'orders.id')
                ->where('status', '!=', FulfillmentStatus::Completed->value)
        );

        $sql = <<<SQL
            CASE
                WHEN orders.status IN (?, ?) THEN ?
                WHEN orders.status NOT IN (?, ?, ?)
                    AND {$hasFailedSql}
                    AND NOT {$hasPendingFailedRefundSql}
                    AND {$hasNonPostedFailedRefundSql}
                THEN ?
                WHEN orders.status = ? THEN ?
                WHEN orders.status = ? THEN ?
                WHEN orders.status NOT IN (?, ?, ?, ?)
                    AND NOT {$hasMissingFulfillmentSql}
                    AND {$hasFulfillmentSql}
                    AND NOT {$hasNonCompletedFulfillmentSql}
                THEN ?
                WHEN orders.status = ? THEN ?
                ELSE ?
            END
            SQL;

        return [
            $sql,
            [
                OrderStatus::PendingPayment->value,
                OrderStatus::Failed->value,
                self::NEEDS_ATTENTION,
                OrderStatus::Fulfilled->value,
                OrderStatus::Refunded->value,
                OrderStatus::Cancelled->value,
                ...$hasFailedBindings,
                ...$hasPendingFailedRefundBindings,
                ...$hasNonPostedFailedRefundBindings,
                self::NEEDS_ATTENTION,
                OrderStatus::Refunded->value,
                self::REFUNDED,
                OrderStatus::Fulfilled->value,
                self::DELIVERED,
                OrderStatus::PendingPayment->value,
                OrderStatus::Failed->value,
                OrderStatus::Refunded->value,
                OrderStatus::Cancelled->value,
                ...$hasMissingFulfillmentBindings,
                ...$hasFulfillmentBindings,
                ...$hasNonCompletedFulfillmentBindings,
                self::DELIVERED,
                OrderStatus::Cancelled->value,
                self::OTHER,
                self::IN_PROGRESS,
            ],
        ];
    }

    private function failedFulfillmentsQuery(): Builder
    {
        return Fulfillment::query()
            ->whereColumn('fulfillments.order_id', 'orders.id')
            ->where('status', FulfillmentStatus::Failed->value);
    }

    /**
     * @return array{0: string, 1: list<mixed>}
     */
    private function existsExpression(Builder $query): array
    {
        $query->selectRaw('1');

        return [
            'EXISTS ('.$query->toSql().')',
            $query->getBindings(),
        ];
    }
}
