<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Actions\Fulfillments\Concerns\TransitionsFulfillmentToProcessing;
use App\Enums\FulfillmentStatus;
use App\Models\Fulfillment;
use App\Models\User;
use App\Support\FulfillmentListBroadcaster;
use Illuminate\Support\Facades\DB;

class StartFulfillment
{
    use TransitionsFulfillmentToProcessing;

    public function handle(
        Fulfillment $fulfillment,
        string $actor = 'system',
        ?int $actorId = null,
        array $meta = []
    ): Fulfillment {
        return DB::transaction(function () use ($fulfillment, $actor, $actorId, $meta): Fulfillment {
            $lockedFulfillment = Fulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedFulfillment->status !== FulfillmentStatus::Queued) {
                return $lockedFulfillment;
            }

            if ($actorId !== null && $this->mustOwnClaimToStart($actorId, $lockedFulfillment)) {
                return $lockedFulfillment;
            }

            $this->transitionToProcessing($lockedFulfillment, $actor, $actorId, $meta);

            FulfillmentListBroadcaster::dispatch($lockedFulfillment->id, 'processing');

            return $lockedFulfillment->refresh();
        });
    }

    private function mustOwnClaimToStart(int $actorId, Fulfillment $fulfillment): bool
    {
        $actor = User::query()->find($actorId);

        if ($actor?->hasRole('admin')) {
            return false;
        }

        return $fulfillment->claimed_by !== $actorId;
    }
}
