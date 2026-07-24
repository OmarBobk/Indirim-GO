<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\FulfillmentLogLevel;
use App\Enums\FulfillmentStatus;
use App\Enums\OrderStatus;
use App\Enums\WalletTransactionType;
use App\Events\FulfillmentListChanged;
use App\Jobs\DispatchFulfillmentAutomationJob;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FulfillmentAutomationService;
use Illuminate\Support\Facades\DB;

class RetryFulfillment
{
    public function handle(
        Fulfillment $fulfillment,
        string $actor = 'system',
        ?int $actorId = null
    ): Fulfillment {
        return DB::transaction(function () use ($fulfillment, $actor, $actorId): Fulfillment {
            $lockedFulfillment = Fulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedFulfillment->status !== FulfillmentStatus::Failed) {
                return $lockedFulfillment;
            }

            $orderItem = OrderItem::query()
                ->whereKey($lockedFulfillment->order_item_id)
                ->lockForUpdate()
                ->firstOrFail();

            $order = Order::query()
                ->whereKey($orderItem->order_id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($order->status === OrderStatus::Refunded) {
                return $lockedFulfillment;
            }

            $hasRefund = WalletTransaction::query()
                ->where('type', WalletTransactionType::Refund->value)
                ->whereIn('status', [WalletTransaction::STATUS_PENDING, WalletTransaction::STATUS_POSTED])
                ->where(function ($query) use ($orderItem, $lockedFulfillment): void {
                    $query->where(function ($subQuery) use ($lockedFulfillment): void {
                        $subQuery->where('reference_type', Fulfillment::class)
                            ->where('reference_id', $lockedFulfillment->id);
                    })->orWhere(function ($subQuery) use ($orderItem): void {
                        $subQuery->where('reference_type', OrderItem::class)
                            ->where('reference_id', $orderItem->id);
                    });
                })
                ->lockForUpdate()
                ->exists();

            if ($hasRefund) {
                return $lockedFulfillment;
            }

            app(CancelFulfillmentAutomationRun::class)->handle($lockedFulfillment, 'retry');

            $retryCount = (int) data_get($lockedFulfillment->meta, 'retry_count', 0) + 1;
            $meta = $lockedFulfillment->meta ?? [];
            unset($meta['automation']);
            $statusFrom = $lockedFulfillment->status;

            $lockedFulfillment->fill([
                'status' => FulfillmentStatus::Queued,
                'claimed_by' => null,
                'claimed_at' => null,
                'last_error' => null,
                'processed_at' => null,
                'completed_at' => null,
                'meta' => array_merge($meta, [
                    'retry_count' => $retryCount,
                    'last_retry_at' => now()->toIso8601String(),
                    'last_retry_by' => $actorId,
                    'last_retry_actor' => $actor,
                ]),
            ])->save();

            $fulfillments = Fulfillment::query()
                ->where('order_item_id', $orderItem->id)
                ->lockForUpdate()
                ->get();
            $orderItem->syncStatusFromFulfillments($fulfillments);

            app(AppendFulfillmentLog::class)->handle(
                $lockedFulfillment,
                FulfillmentLogLevel::Info,
                'Fulfillment queued',
                $this->buildContext('retry_requested', $actor, $actorId)
            );

            activity()
                ->inLog('fulfillment')
                ->event('fulfillment.retry_requested')
                ->performedOn($lockedFulfillment)
                ->causedBy($actorId ? User::query()->find($actorId) : null)
                ->withProperties(array_filter([
                    'fulfillment_id' => $lockedFulfillment->id,
                    'order_id' => $lockedFulfillment->order_id,
                    'order_item_id' => $lockedFulfillment->order_item_id,
                    'status_from' => $statusFrom->value,
                    'status_to' => FulfillmentStatus::Queued->value,
                    'retry_count' => $retryCount,
                    'actor' => $actor,
                    'actor_id' => $actorId,
                ], fn ($value) => $value !== null && $value !== ''))
                ->log('Fulfillment retry requested');

            $fulfillmentId = $lockedFulfillment->id;
            DB::afterCommit(static function () use ($fulfillmentId): void {
                event(new FulfillmentListChanged($fulfillmentId, 'created'));
                $fulfillment = Fulfillment::query()->find($fulfillmentId);
                if ($fulfillment !== null && app(FulfillmentAutomationService::class)->isEligible($fulfillment)) {
                    DispatchFulfillmentAutomationJob::dispatch($fulfillmentId);
                }
            });

            return $lockedFulfillment->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(string $action, string $actor, ?int $actorId): array
    {
        return array_filter([
            'action' => $action,
            'actor' => $actor,
            'actor_id' => $actorId,
        ], fn ($value) => $value !== null);
    }
}
