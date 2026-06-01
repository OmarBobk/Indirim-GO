<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentLogLevel;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use App\Services\FulfillmentAutomationService;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReserveFulfillmentAutomationRun
{
    public function __construct(
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    public function handle(Fulfillment $fulfillment): FulfillmentAutomationRun
    {
        return DB::transaction(function () use ($fulfillment): FulfillmentAutomationRun {
            $locked = Fulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! $this->automationService->isEligible($locked)) {
                throw new RuntimeException('Fulfillment is not eligible for automation.');
            }

            $supplierKey = $locked->browserSupplierKey();

            if ($supplierKey === null) {
                throw new RuntimeException('Fulfillment has no browser supplier key.');
            }

            $attempt = $this->automationService->nextAttemptNumber($locked);

            $run = FulfillmentAutomationRun::query()->create([
                'fulfillment_id' => $locked->id,
                'supplier_key' => $supplierKey,
                'status' => FulfillmentAutomationRunStatus::Reserved,
                'attempt' => $attempt,
                'idempotency_key' => $this->automationService->buildIdempotencyKey($locked->id, $attempt),
            ]);

            app(AppendFulfillmentLog::class)->handle(
                $locked,
                FulfillmentLogLevel::Info,
                'Automation run reserved',
                [
                    'action' => 'automation_reserved',
                    'run_uuid' => $run->uuid,
                    'attempt' => $attempt,
                ],
            );

            return $run;
        });
    }
}
