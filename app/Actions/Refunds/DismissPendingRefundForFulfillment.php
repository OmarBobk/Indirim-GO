<?php

declare(strict_types=1);

namespace App\Actions\Refunds;

use App\Actions\Fulfillments\AppendFulfillmentLog;
use App\Enums\FulfillmentLogLevel;
use App\Enums\WalletTransactionType;
use App\Models\Fulfillment;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\DB;

class DismissPendingRefundForFulfillment
{
    public function handle(Fulfillment $fulfillment, ?int $actorId = null, ?string $note = null, string $dismissReason = 'fulfillment_completed'): ?WalletTransaction
    {
        return DB::transaction(function () use ($fulfillment, $actorId, $note, $dismissReason): ?WalletTransaction {
            $lockedFulfillment = Fulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->first();

            if ($lockedFulfillment === null) {
                return null;
            }

            $transaction = WalletTransaction::query()
                ->where('reference_type', Fulfillment::class)
                ->where('reference_id', $lockedFulfillment->id)
                ->where('type', WalletTransactionType::Refund)
                ->where('status', WalletTransaction::STATUS_PENDING)
                ->lockForUpdate()
                ->first();

            if ($transaction === null) {
                return null;
            }

            $dismissNote = $note ?? __('messages.refund_dismissed_fulfillment_completed');
            $now = now()->toIso8601String();

            $transaction->status = WalletTransaction::STATUS_REJECTED;
            $transaction->meta = array_merge($transaction->meta ?? [], array_filter([
                'state' => 'refund_rejected',
                'rejected_by' => $actorId,
                'rejected_at' => $now,
                'note' => ($transaction->meta['note'] ?? null)
                    ? $transaction->meta['note'].' | '.$dismissNote
                    : $dismissNote,
                'dismiss_reason' => $dismissReason,
            ], fn ($value) => $value !== null && $value !== ''));
            $transaction->save();

            $fulfillmentMeta = $lockedFulfillment->meta ?? [];
            $fulfillmentMeta['refund'] = array_merge($fulfillmentMeta['refund'] ?? [], array_filter([
                'status' => WalletTransaction::STATUS_REJECTED,
                'rejected_by' => $actorId,
                'rejected_at' => $now,
                'dismiss_reason' => $dismissReason,
            ], fn ($value) => $value !== null && $value !== ''));
            $lockedFulfillment->update(['meta' => $fulfillmentMeta]);

            app(AppendFulfillmentLog::class)->handle(
                $lockedFulfillment,
                FulfillmentLogLevel::Info,
                'Pending refund dismissed after fulfillment completed',
                array_filter([
                    'action' => 'refund_dismissed',
                    'actor_type' => $actorId !== null ? 'admin' : 'system',
                    'actor_id' => $actorId,
                    'transaction_id' => $transaction->id,
                ], fn ($value) => $value !== null)
            );

            return $transaction;
        });
    }
}
