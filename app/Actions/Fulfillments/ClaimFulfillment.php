<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Actions\Fulfillments\Concerns\TransitionsFulfillmentToProcessing;
use App\Enums\FulfillmentStatus;
use App\Events\FulfillmentListChanged;
use App\Models\Fulfillment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClaimFulfillment
{
    use TransitionsFulfillmentToProcessing;

    public function handle(Fulfillment $fulfillment, int $actorId): Fulfillment
    {
        return DB::transaction(function () use ($fulfillment, $actorId): Fulfillment {
            $lockedFulfillment = Fulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            app(CancelFulfillmentAutomationRun::class)->handle($lockedFulfillment, 'human_claim');

            $lockedFulfillment->refresh();

            if (
                $lockedFulfillment->status === FulfillmentStatus::Processing
                && $lockedFulfillment->claimed_by === null
            ) {
                $lockedFulfillment->fill([
                    'status' => FulfillmentStatus::Queued,
                    'processed_at' => null,
                ])->save();
            }

            if ($lockedFulfillment->claimed_by === $actorId && $lockedFulfillment->status === FulfillmentStatus::Processing) {
                return $lockedFulfillment;
            }

            if ($lockedFulfillment->status !== FulfillmentStatus::Queued || $lockedFulfillment->claimed_by !== null) {
                throw ValidationException::withMessages([
                    'fulfillment' => __('messages.fulfillment_already_claimed'),
                ]);
            }

            $lockedUser = User::query()
                ->whereKey($actorId)
                ->lockForUpdate()
                ->firstOrFail();

            $activeTaskCount = Fulfillment::query()
                ->where('claimed_by', $lockedUser->id)
                ->where('status', FulfillmentStatus::Processing)
                ->count();

            if ($activeTaskCount >= 5) {
                throw ValidationException::withMessages([
                    'fulfillment' => __('messages.fulfillment_claim_limit_reached'),
                ]);
            }

            $lockedFulfillment->fill([
                'claimed_by' => $actorId,
                'claimed_at' => now(),
            ])->save();

            $this->transitionToProcessing(
                $lockedFulfillment,
                'admin',
                $actorId,
                ['source' => 'claim']
            );

            $fulfillmentId = $lockedFulfillment->id;
            DB::afterCommit(static function () use ($fulfillmentId): void {
                event(new FulfillmentListChanged($fulfillmentId, 'claimed'));
            });

            return $lockedFulfillment->refresh();
        });
    }
}
