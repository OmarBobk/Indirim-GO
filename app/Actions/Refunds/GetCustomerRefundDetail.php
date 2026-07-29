<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\DTOs\Financial\FinancialDestinationDTO;
use App\DTOs\Refunds\CustomerRefundDetailDTO;
use App\Enums\CustomerRefundStatus;
use App\Enums\FinancialDestinationType;
use App\Enums\FulfillmentStatus;
use App\Enums\ProductAmountMode;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Refunds\CustomerRefundClassifier;
use App\Support\WalletTransactionPublicRef;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Owned customer refund detail read model (M6.4).
 */
final class GetCustomerRefundDetail
{
    public function handle(User $user, string $publicRef): CustomerRefundDetailDTO
    {
        $normalized = WalletTransactionPublicRef::normalize($publicRef);

        if (! WalletTransactionPublicRef::isValidFormat($normalized)) {
            throw new NotFoundHttpException;
        }

        $wallet = Wallet::query()->where('user_id', $user->id)->first();
        if ($wallet === null) {
            throw new NotFoundHttpException;
        }

        $tx = WalletTransaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('type', WalletTransactionType::Refund)
            ->where('public_ref', $normalized)
            ->first();

        if ($tx === null) {
            throw new NotFoundHttpException;
        }

        return $this->map($tx);
    }

    private function map(WalletTransaction $tx): CustomerRefundDetailDTO
    {
        $ff = $this->loadFulfillment($tx);
        $item = $this->loadOrderItem($tx, $ff);
        $ffStatus = $ff?->status instanceof FulfillmentStatus ? $ff->status : null;
        $status = CustomerRefundClassifier::classify($tx, $ffStatus);

        $publicRef = (string) $tx->public_ref;
        $orderNumber = data_get($tx->meta, 'order_number');
        $orderNumber = is_string($orderNumber) && trim($orderNumber) !== '' ? trim($orderNumber) : null;

        $requestedAt = $this->parseMetaTime($tx, 'requested_at')
            ?? ($tx->created_at instanceof Carbon ? $tx->created_at : Carbon::parse((string) $tx->created_at));

        $reviewedAt = $this->parseMetaTime($tx, 'approved_at')
            ?? $this->parseMetaTime($tx, 'rejected_at');

        $postedAt = null;
        if ($tx->status === WalletTransaction::STATUS_POSTED && $tx->posted_at !== null) {
            $postedAt = $tx->posted_at instanceof Carbon
                ? $tx->posted_at
                : Carbon::parse((string) $tx->posted_at);
            $reviewedAt = $reviewedAt ?? $postedAt;
        }

        $moneyMoved = CustomerRefundClassifier::moneyMoved($status);
        $canRecover = CustomerRefundClassifier::canCustomerRecover($status);

        $timeline = [
            [
                'key' => 'delivery_failed',
                'label_key' => 'refund_timeline_delivery_failed',
                'occurred_at' => null,
            ],
            [
                'key' => 'requested',
                'label_key' => 'refund_timeline_requested',
                'occurred_at' => $requestedAt->toIso8601String(),
            ],
        ];

        if ($status === CustomerRefundStatus::UnderReview) {
            $timeline[] = [
                'key' => 'under_review',
                'label_key' => 'refund_timeline_under_review',
                'occurred_at' => null,
            ];
        }

        if ($moneyMoved && $postedAt !== null) {
            $timeline[] = [
                'key' => 'refunded',
                'label_key' => 'refund_timeline_refunded',
                'occurred_at' => $postedAt->toIso8601String(),
            ];
        }

        if ($status === CustomerRefundStatus::NeedsAction && $reviewedAt !== null) {
            $timeline[] = [
                'key' => 'rejected',
                'label_key' => 'refund_timeline_rejected',
                'occurred_at' => $reviewedAt->toIso8601String(),
            ];
        }

        if ($status === CustomerRefundStatus::Closed) {
            $timeline[] = [
                'key' => 'closed',
                'label_key' => 'refund_timeline_closed',
                'occurred_at' => $reviewedAt?->toIso8601String(),
            ];
        }

        $quantityContextKey = null;
        $orderItemQuantity = $item !== null ? (int) $item->quantity : null;
        if ($item !== null) {
            $mode = $item->amount_mode ?? ProductAmountMode::Fixed;
            if ($mode === ProductAmountMode::Custom) {
                $quantityContextKey = 'custom';
            } elseif ((int) $item->quantity > 1) {
                $quantityContextKey = 'unit_of';
            }
        }

        $ffStatusKey = match ($ffStatus) {
            FulfillmentStatus::Failed => 'refund_fulfillment_failed',
            FulfillmentStatus::Completed => 'refund_fulfillment_completed',
            FulfillmentStatus::Processing => 'refund_fulfillment_processing',
            default => $ff !== null ? 'refund_fulfillment_other' : null,
        };

        return new CustomerRefundDetailDTO(
            publicReference: $publicRef,
            status: $status,
            amount: bcadd((string) $tx->amount, '0', 2),
            currency: strtoupper((string) (data_get($tx->meta, 'currency') ?: 'USD')),
            requestedAt: $requestedAt,
            reviewedAt: $reviewedAt,
            postedAt: $postedAt,
            moneyMoved: $moneyMoved,
            canRecover: $canRecover,
            isIntegrityAnomaly: $status === CustomerRefundStatus::IntegrityAnomaly,
            customerSafeReason: CustomerRefundClassifier::customerSafeReason($tx),
            orderNumber: $orderNumber,
            productLabel: $item?->name,
            quantityContextKey: $quantityContextKey,
            orderItemQuantity: $orderItemQuantity,
            fulfillmentStatusLabelKey: $ffStatusKey,
            timeline: $timeline,
            destination: new FinancialDestinationDTO(
                FinancialDestinationType::WalletRefundDetail,
                ['public_ref' => $publicRef]
            ),
            orderDestination: $orderNumber !== null
                ? new FinancialDestinationDTO(
                    FinancialDestinationType::OrderDetail,
                    ['order_number' => $orderNumber]
                )
                : new FinancialDestinationDTO(FinancialDestinationType::Orders),
            ledgerDestination: $moneyMoved
                ? new FinancialDestinationDTO(
                    FinancialDestinationType::WalletTransactionsSearch,
                    ['search' => $publicRef]
                )
                : null,
            recoveryDestination: $canRecover && $orderNumber !== null
                ? new FinancialDestinationDTO(
                    FinancialDestinationType::OrderDetail,
                    ['order_number' => $orderNumber]
                )
                : ($canRecover ? new FinancialDestinationDTO(FinancialDestinationType::Orders) : null),
        );
    }

    private function loadFulfillment(WalletTransaction $tx): ?Fulfillment
    {
        $id = $tx->reference_type === Fulfillment::class && is_numeric($tx->reference_id)
            ? (int) $tx->reference_id
            : (is_numeric(data_get($tx->meta, 'fulfillment_id')) ? (int) data_get($tx->meta, 'fulfillment_id') : null);

        if ($id === null) {
            return null;
        }

        return Fulfillment::query()->whereKey($id)->first(['id', 'order_item_id', 'status']);
    }

    private function loadOrderItem(WalletTransaction $tx, ?Fulfillment $ff): ?OrderItem
    {
        $metaId = data_get($tx->meta, 'order_item_id');
        if (is_numeric($metaId)) {
            return OrderItem::query()->whereKey((int) $metaId)->first(['id', 'name', 'quantity', 'amount_mode']);
        }

        if ($ff !== null) {
            return OrderItem::query()->whereKey((int) $ff->order_item_id)->first(['id', 'name', 'quantity', 'amount_mode']);
        }

        return null;
    }

    private function parseMetaTime(WalletTransaction $tx, string $key): ?Carbon
    {
        $value = data_get($tx->meta, $key);
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
