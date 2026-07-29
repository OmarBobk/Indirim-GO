<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Actions\Fulfillments\AppendFulfillmentLog;
use App\Enums\CustomerActivityInvalidationReason;
use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\FulfillmentLogLevel;
use App\Enums\FulfillmentStatus;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Notifications\RefundRejectedNotification;
use App\Services\SystemEventService;
use App\Support\AdminOpsBroadcaster;
use App\Support\CustomerActivityBroadcaster;
use App\Support\CustomerFinancialBroadcaster;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RejectRefundRequest
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
                return $transaction;
            }

            if ($transaction->type !== WalletTransactionType::Refund) {
                throw ValidationException::withMessages([
                    'refund' => __('messages.refund_not_allowed'),
                ]);
            }

            if (! in_array($transaction->reference_type, [Fulfillment::class, OrderItem::class], true)) {
                throw ValidationException::withMessages([
                    'refund' => __('messages.refund_not_allowed'),
                ]);
            }

            $orderItem = null;
            $fulfillment = null;

            if ($transaction->reference_type === Fulfillment::class) {
                $fulfillment = Fulfillment::query()
                    ->whereKey($transaction->reference_id)
                    ->lockForUpdate()
                    ->firstOrFail();
                $orderItem = OrderItem::query()
                    ->whereKey($fulfillment->order_item_id)
                    ->lockForUpdate()
                    ->firstOrFail();
            } else {
                $orderItem = OrderItem::query()
                    ->whereKey($transaction->reference_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $fulfillmentId = (int) data_get($transaction->meta, 'fulfillment_id', 0);
                if ($fulfillmentId > 0) {
                    $fulfillment = Fulfillment::query()
                        ->whereKey($fulfillmentId)
                        ->lockForUpdate()
                        ->first();
                }

                if ($fulfillment === null) {
                    $fulfillment = Fulfillment::query()
                        ->where('order_item_id', $orderItem->id)
                        ->lockForUpdate()
                        ->oldest('id')
                        ->first();
                }
            }

            if ($fulfillment === null || $fulfillment->status !== FulfillmentStatus::Failed) {
                throw ValidationException::withMessages([
                    'refund' => __('messages.refund_not_allowed'),
                ]);
            }

            $transaction->status = WalletTransaction::STATUS_REJECTED;
            $transaction->meta = array_merge($transaction->meta ?? [], [
                'state' => 'refund_rejected',
                'rejected_by' => $adminId,
                'rejected_at' => now()->toIso8601String(),
            ]);
            $transaction->save();

            $fulfillmentMeta = $fulfillment->meta ?? [];
            $fulfillmentMeta['refund'] = array_merge($fulfillmentMeta['refund'] ?? [], [
                'status' => WalletTransaction::STATUS_REJECTED,
                'rejected_by' => $adminId,
                'rejected_at' => now()->toIso8601String(),
            ]);
            $fulfillment->update(['meta' => $fulfillmentMeta]);

            app(AppendFulfillmentLog::class)->handle(
                $fulfillment,
                FulfillmentLogLevel::Info,
                'Refund rejected',
                [
                    'action' => 'refund_rejected',
                    'actor_type' => 'admin',
                    'actor_id' => $adminId,
                    'transaction_id' => $transaction->id,
                ]
            );

            activity()
                ->inLog('payments')
                ->event('refund.rejected')
                ->performedOn($transaction)
                ->causedBy(User::query()->find($adminId))
                ->withProperties(array_filter([
                    'transaction_id' => $transaction->id,
                    'order_id' => data_get($transaction->meta, 'order_id'),
                    'order_item_id' => $orderItem->id,
                    'fulfillment_id' => $fulfillment->id,
                    'amount' => $transaction->amount,
                    'currency' => data_get($transaction->meta, 'currency', 'USD'),
                ], fn ($value) => $value !== null && $value !== ''))
                ->log('Refund rejected');

            $rejectedTransactionId = $transaction->id;
            $orderOwnerId = (int) data_get($transaction->meta, 'user_id', 0);
            $adminIdForEvent = $adminId;

            if ($orderOwnerId > 0) {
                CustomerActivityBroadcaster::dispatch(
                    $orderOwnerId,
                    CustomerActivityInvalidationReason::RefundStateChanged,
                );
                CustomerFinancialBroadcaster::dispatch(
                    $orderOwnerId,
                    CustomerFinancialInvalidationReason::RefundStateChanged,
                );
            }

            DB::afterCommit(function () use ($rejectedTransactionId, $orderOwnerId, $adminIdForEvent): void {
                $tx = WalletTransaction::query()->find($rejectedTransactionId);
                if ($tx === null || $orderOwnerId === 0) {
                    return;
                }
                $admin = User::query()->find($adminIdForEvent);
                app(SystemEventService::class)->record(
                    'admin.rejected.refund',
                    $tx,
                    $admin,
                    [
                        'order_id' => data_get($tx->meta, 'order_id'),
                        'amount' => (float) $tx->amount,
                    ],
                    'info',
                    false,
                );
                $owner = User::query()->find($orderOwnerId);
                if ($owner !== null) {
                    $owner->notify(RefundRejectedNotification::fromRefundTransaction($tx));
                }
            });

            AdminOpsBroadcaster::dispatch('refund-rejected');

            return $transaction;
        });
    }
}
