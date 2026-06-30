<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Actions\Refunds\DismissPendingRefundForFulfillment;
use App\Enums\FulfillmentLogLevel;
use App\Enums\FulfillmentStatus;
use App\Events\FulfillmentListChanged;
use App\Jobs\EvaluateLoyaltyForUser;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Notifications\FulfillmentCompletedNotification;
use Illuminate\Support\Facades\DB;

class CompleteFulfillment
{
    public function handle(
        Fulfillment $fulfillment,
        ?array $deliveredPayload = null,
        string $actor = 'system',
        ?int $actorId = null
    ): Fulfillment {
        return DB::transaction(function () use ($fulfillment, $deliveredPayload, $actor, $actorId): Fulfillment {
            $lockedFulfillment = Fulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedFulfillment->status === FulfillmentStatus::Completed) {
                return $lockedFulfillment;
            }

            if ($lockedFulfillment->status === FulfillmentStatus::Cancelled) {
                return $lockedFulfillment;
            }

            $statusFrom = $lockedFulfillment->status;

            $meta = $lockedFulfillment->meta ?? [];
            $meta['delivered_payload'] = $deliveredPayload;

            $lockedFulfillment->fill([
                'status' => FulfillmentStatus::Completed,
                'processed_at' => $lockedFulfillment->processed_at ?? now(),
                'completed_at' => $lockedFulfillment->completed_at ?? now(),
                'last_error' => null,
                'meta' => $meta,
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

            app(DismissPendingRefundForFulfillment::class)->handle(
                $lockedFulfillment,
                $actorId,
            );

            app(AppendFulfillmentLog::class)->handle(
                $lockedFulfillment,
                FulfillmentLogLevel::Info,
                'Fulfillment completed',
                $this->buildContext('completed', $actor, $actorId)
            );

            activity()
                ->inLog('fulfillment')
                ->event('fulfillment.completed')
                ->performedOn($lockedFulfillment)
                ->causedBy($actorId ? User::query()->find($actorId) : null)
                ->withProperties(array_filter([
                    'fulfillment_id' => $lockedFulfillment->id,
                    'order_id' => $lockedFulfillment->order_id,
                    'order_item_id' => $lockedFulfillment->order_item_id,
                    'provider' => $lockedFulfillment->provider,
                    'status_from' => $statusFrom->value,
                    'status_to' => FulfillmentStatus::Completed->value,
                    'actor' => $actor,
                    'actor_id' => $actorId,
                ], fn ($value) => $value !== null && $value !== ''))
                ->log('Fulfillment completed');

            $userId = Order::query()->where('id', $lockedFulfillment->order_id)->value('user_id');
            $completedFulfillmentId = $lockedFulfillment->id;
            if ($userId !== null) {
                DB::afterCommit(function () use ($userId, $completedFulfillmentId): void {
                    dispatch(new EvaluateLoyaltyForUser((int) $userId));
                    $fulfillment = Fulfillment::query()->find($completedFulfillmentId);
                    if ($fulfillment !== null) {
                        $owner = User::query()->find($userId);
                        if ($owner !== null) {
                            $owner->notify(FulfillmentCompletedNotification::fromFulfillment($fulfillment));
                        }
                    }
                });
            }

            $fulfillmentId = $lockedFulfillment->id;
            DB::afterCommit(static function () use ($fulfillmentId): void {
                event(new FulfillmentListChanged($fulfillmentId, 'completed'));
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
