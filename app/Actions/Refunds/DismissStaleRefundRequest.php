<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Enums\FulfillmentStatus;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Support\AdminOpsBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DismissStaleRefundRequest
{
    public function handle(int $transactionId, int $adminId): WalletTransaction
    {
        return DB::transaction(function () use ($transactionId, $adminId): WalletTransaction {
            $transaction = WalletTransaction::query()
                ->whereKey($transactionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($transaction->status === WalletTransaction::STATUS_REJECTED) {
                return $transaction;
            }

            if ($transaction->status !== WalletTransaction::STATUS_PENDING) {
                throw ValidationException::withMessages([
                    'refund' => __('messages.refund_not_allowed'),
                ]);
            }

            if ($transaction->type !== WalletTransactionType::Refund) {
                throw ValidationException::withMessages([
                    'refund' => __('messages.refund_not_allowed'),
                ]);
            }

            $fulfillment = $this->resolveFulfillment($transaction);

            if ($fulfillment === null) {
                throw ValidationException::withMessages([
                    'refund' => __('messages.refund_not_allowed'),
                ]);
            }

            if ($fulfillment->status === FulfillmentStatus::Failed) {
                throw ValidationException::withMessages([
                    'refund' => __('messages.refund_dismiss_use_reject'),
                ]);
            }

            $dismissed = app(DismissPendingRefundForFulfillment::class)->handle(
                $fulfillment,
                $adminId,
                __('messages.refund_dismissed_stale'),
                'stale_refund',
            );

            if ($dismissed === null) {
                throw ValidationException::withMessages([
                    'refund' => __('messages.refund_not_allowed'),
                ]);
            }

            activity()
                ->inLog('payments')
                ->event('refund.dismissed')
                ->performedOn($dismissed)
                ->causedBy(User::query()->find($adminId))
                ->withProperties([
                    'transaction_id' => $dismissed->id,
                    'fulfillment_id' => $fulfillment->id,
                    'fulfillment_status' => $fulfillment->status->value,
                    'reason' => 'stale_refund',
                ])
                ->log('Stale refund dismissed');

            AdminOpsBroadcaster::dispatch('refund-dismissed-stale');

            return $dismissed;
        });
    }

    private function resolveFulfillment(WalletTransaction $transaction): ?Fulfillment
    {
        if ($transaction->reference_type === Fulfillment::class) {
            return Fulfillment::query()
                ->whereKey($transaction->reference_id)
                ->lockForUpdate()
                ->first();
        }

        if ($transaction->reference_type !== OrderItem::class) {
            return null;
        }

        $fulfillmentId = (int) data_get($transaction->meta, 'fulfillment_id', 0);
        if ($fulfillmentId > 0) {
            $fulfillment = Fulfillment::query()
                ->whereKey($fulfillmentId)
                ->lockForUpdate()
                ->first();

            if ($fulfillment !== null) {
                return $fulfillment;
            }
        }

        return Fulfillment::query()
            ->where('order_item_id', $transaction->reference_id)
            ->lockForUpdate()
            ->oldest('id')
            ->first();
    }
}
