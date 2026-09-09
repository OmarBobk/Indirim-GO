<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\FulfillmentLogLevel;
use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Notifications\FulfillmentFailedNotification;
use App\Notifications\FulfillmentProcessFailedNotification;
use App\Services\NotificationRecipientService;
use App\Services\OperationalIntelligenceService;
use App\Support\FulfillmentListBroadcaster;
use Illuminate\Support\Facades\DB;

class FailFulfillment
{
    public function handle(
        Fulfillment $fulfillment,
        string $reason,
        string $actor = 'system',
        ?int $actorId = null
    ): Fulfillment {
        return DB::transaction(function () use ($fulfillment, $reason, $actor, $actorId): Fulfillment {
            $lockedFulfillment = Fulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedFulfillment->status === FulfillmentStatus::Failed) {
                return $lockedFulfillment;
            }

            if ($lockedFulfillment->status === FulfillmentStatus::Completed) {
                return $lockedFulfillment;
            }

            if ($lockedFulfillment->status === FulfillmentStatus::Cancelled) {
                return $lockedFulfillment;
            }

            $statusFrom = $lockedFulfillment->status;

            $lockedFulfillment->fill([
                'status' => FulfillmentStatus::Failed,
                'processed_at' => $lockedFulfillment->processed_at ?? now(),
                'last_error' => $reason,
            ])->save();

            $orderItem = OrderItem::query()
                ->whereKey($lockedFulfillment->order_item_id)
                ->lockForUpdate()
                ->firstOrFail();
            $fulfillments = Fulfillment::query()
                ->where('order_item_id', $orderItem->id)
                ->lockForUpdate()
                ->get();
            $orderItem->syncStatusFromFulfillments($fulfillments);

            app(AppendFulfillmentLog::class)->handle(
                $lockedFulfillment,
                FulfillmentLogLevel::Error,
                'Fulfillment failed',
                $this->buildContext('failed', $actor, $actorId, $reason)
            );

            activity()
                ->inLog('fulfillment')
                ->event('fulfillment.failed')
                ->performedOn($lockedFulfillment)
                ->causedBy($actorId ? User::query()->find($actorId) : null)
                ->withProperties(array_filter([
                    'fulfillment_id' => $lockedFulfillment->id,
                    'order_id' => $lockedFulfillment->order_id,
                    'order_item_id' => $lockedFulfillment->order_item_id,
                    'provider' => $lockedFulfillment->provider,
                    'status_from' => $statusFrom->value,
                    'status_to' => FulfillmentStatus::Failed->value,
                    'reason' => $reason,
                    'actor' => $actor,
                    'actor_id' => $actorId,
                ], fn ($value) => $value !== null && $value !== ''))
                ->log('Fulfillment failed');

            $failedFulfillmentId = $lockedFulfillment->id;
            $failureReason = $reason;
            $orderOwnerId = Order::query()->where('id', $lockedFulfillment->order_id)->value('user_id');
            DB::afterCommit(function () use ($failedFulfillmentId, $failureReason, $orderOwnerId): void {
                $fulfillment = Fulfillment::query()->find($failedFulfillmentId);
                if ($fulfillment === null) {
                    return;
                }
                app(OperationalIntelligenceService::class)->detectFulfillmentFailure($fulfillment);
                try {
                    if ($orderOwnerId !== null) {
                        $owner = User::query()->find($orderOwnerId);
                        if ($owner !== null) {
                            $owner->notify(FulfillmentFailedNotification::fromFulfillment($fulfillment, $failureReason));
                        }
                    }
                    $adminNotification = FulfillmentProcessFailedNotification::fromFulfillment($fulfillment, $failureReason);
                    app(NotificationRecipientService::class)->adminUsers()->each(fn ($admin) => $admin->notify($adminNotification));
                } catch (\Throwable $exception) {
                    // Optional notification/broadcast channels must not reverse durable failure state.
                    report($exception);
                }
            });

            FulfillmentListBroadcaster::dispatch($lockedFulfillment->id, 'failed');

            return $lockedFulfillment->refresh();
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(string $action, string $actor, ?int $actorId, string $reason): array
    {
        return array_filter([
            'action' => $action,
            'actor' => $actor,
            'actor_id' => $actorId,
            'reason' => $reason,
        ], fn ($value) => $value !== null);
    }
}
