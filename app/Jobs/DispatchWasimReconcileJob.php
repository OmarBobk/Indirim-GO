<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Actions\Fulfillments\DispatchFulfillmentAutomationRun;
use App\Actions\Fulfillments\ReserveFulfillmentAutomationReconcileRun;
use App\Models\Fulfillment;
use App\Services\FulfillmentAutomationService;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

class DispatchWasimReconcileJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    /**
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    public function __construct(
        public readonly int $fulfillmentId,
    ) {
        $this->onQueue((string) config('fulfillment_automation.queue', 'fulfillment-automation'));
    }

    public function uniqueId(): string
    {
        return 'fulfillment:reconcile:'.$this->fulfillmentId;
    }

    public function handle(
        FulfillmentAutomationService $automationService,
        ReserveFulfillmentAutomationReconcileRun $reserve,
        DispatchFulfillmentAutomationRun $dispatchRun,
    ): void {
        $fulfillment = Fulfillment::query()->find($this->fulfillmentId);

        if ($fulfillment === null || ! $automationService->isEligibleForReconcile($fulfillment)) {
            return;
        }

        try {
            $run = $reserve->handle($fulfillment);

            $dispatchRun->handle($run->refresh(), $fulfillment->refresh());
        } catch (Throwable $exception) {
            Log::warning('DispatchWasimReconcileJob failed', [
                'fulfillment_id' => $this->fulfillmentId,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }
    }
}
