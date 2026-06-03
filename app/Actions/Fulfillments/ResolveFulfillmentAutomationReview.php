<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentLogLevel;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ResolveFulfillmentAutomationReview
{
    public function handle(FulfillmentAutomationRun $run, string $outcome, ?int $actorId = null): FulfillmentAutomationRun
    {
        if ($run->status !== FulfillmentAutomationRunStatus::NeedsReview) {
            throw new InvalidArgumentException('Run is not awaiting review.');
        }

        if (! in_array($outcome, ['succeeded', 'failed'], true)) {
            throw new InvalidArgumentException('Invalid review outcome.');
        }

        return DB::transaction(function () use ($run, $outcome, $actorId): FulfillmentAutomationRun {
            $lockedRun = FulfillmentAutomationRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedRun->status !== FulfillmentAutomationRunStatus::NeedsReview) {
                return $lockedRun;
            }

            $fulfillment = Fulfillment::query()
                ->whereKey($lockedRun->fulfillment_id)
                ->lockForUpdate()
                ->firstOrFail();

            $fulfillmentMeta = $fulfillment->meta ?? [];
            $fulfillmentMeta['automation'] = array_merge($fulfillmentMeta['automation'] ?? [], [
                'requires_review' => false,
                'reviewed_at' => now()->toIso8601String(),
                'reviewed_by' => $actorId,
                'review_outcome' => $outcome,
            ]);

            if ($outcome === 'succeeded') {
                $deliveredPayload = is_array($lockedRun->result_payload) ? $lockedRun->result_payload : [];
                $deliveredPayload['automation_run_uuid'] = $lockedRun->uuid;
                $deliveredPayload['automation'] = true;
                $deliveredPayload['admin_review'] = true;

                $lockedRun->fill([
                    'status' => FulfillmentAutomationRunStatus::Succeeded,
                    'result_payload' => $deliveredPayload,
                    'finished_at' => $lockedRun->finished_at ?? now(),
                ])->save();

                $fulfillment->update(['meta' => $fulfillmentMeta]);

                app(AppendFulfillmentLog::class)->handle(
                    $fulfillment,
                    FulfillmentLogLevel::Info,
                    'Automation review marked succeeded',
                    [
                        'action' => 'automation_review_succeeded',
                        'run_uuid' => $lockedRun->uuid,
                    ],
                );

                app(CompleteFulfillment::class)->handle(
                    $fulfillment->refresh(),
                    $deliveredPayload,
                    'admin',
                    $actorId,
                );

                app(BroadcastAutomationRunChanged::class)->handle(
                    $lockedRun->uuid,
                    'review_succeeded',
                    FulfillmentAutomationRunStatus::Succeeded->value,
                );

                return $lockedRun->refresh();
            }

            $lockedRun->fill([
                'status' => FulfillmentAutomationRunStatus::Failed,
                'finished_at' => $lockedRun->finished_at ?? now(),
            ])->save();

            $fulfillment->update(['meta' => $fulfillmentMeta]);

            app(AppendFulfillmentLog::class)->handle(
                $fulfillment,
                FulfillmentLogLevel::Error,
                'Automation review marked failed',
                [
                    'action' => 'automation_review_failed',
                    'run_uuid' => $lockedRun->uuid,
                ],
            );

            app(BroadcastAutomationRunChanged::class)->handle(
                $lockedRun->uuid,
                'review_failed',
                FulfillmentAutomationRunStatus::Failed->value,
            );

            return $lockedRun->refresh();
        });
    }
}
