<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\FulfillmentStatus;
use App\Jobs\DispatchFulfillmentAutomationJob;
use App\Models\Fulfillment;
use App\Services\FulfillmentAutomationService;

class RetryFulfillmentAutomation
{
    public function handle(Fulfillment $fulfillment, ?int $actorId = null): Fulfillment
    {
        if (! $fulfillment->isBrowserAutomated()) {
            return $fulfillment;
        }

        if ($fulfillment->status === FulfillmentStatus::Failed) {
            $fulfillment = app(RetryFulfillment::class)->handle($fulfillment, 'admin', $actorId);
        } else {
            app(CancelFulfillmentAutomationRun::class)->handle($fulfillment, 'manual_retry');
            $fulfillment = $fulfillment->refresh();
        }

        if (app(FulfillmentAutomationService::class)->isEligible($fulfillment->refresh())) {
            DispatchFulfillmentAutomationJob::dispatch($fulfillment->id);
        }

        return $fulfillment->refresh();
    }
}
