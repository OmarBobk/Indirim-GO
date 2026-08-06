<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Jobs\DispatchWasimReconcileJob;
use App\Models\Fulfillment;
use App\Services\FulfillmentAutomationService;
use Illuminate\Support\Facades\DB;

class ScheduleWasimOrderReconcile
{
    public function __construct(
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    public function handle(Fulfillment $fulfillment, ?int $attemptNumber = null): void
    {
        if (! $this->automationService->shouldScheduleReconcile($fulfillment, $attemptNumber)) {
            return;
        }

        $delaySeconds = $this->automationService->reconcileDelaySeconds($attemptNumber);
        $nextReconcileAt = now()->addSeconds($delaySeconds);

        $meta = $fulfillment->meta ?? [];
        $meta['automation'] = array_merge($meta['automation'] ?? [], [
            'next_reconcile_at' => $nextReconcileAt->toIso8601String(),
        ]);
        $fulfillment->update(['meta' => $meta]);

        $fulfillmentId = $fulfillment->id;

        DB::afterCommit(function () use ($fulfillmentId, $delaySeconds): void {
            DispatchWasimReconcileJob::dispatch($fulfillmentId)
                ->delay(now()->addSeconds($delaySeconds));
        });
    }
}
