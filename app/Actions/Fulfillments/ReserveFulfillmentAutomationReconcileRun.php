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

class ReserveFulfillmentAutomationReconcileRun
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

            if (! $this->automationService->isEligibleForReconcile($locked)) {
                throw new RuntimeException('Fulfillment is not eligible for Wasim reconcile.');
            }

            $supplierKey = $locked->browserSupplierKey();

            if ($supplierKey === null) {
                throw new RuntimeException('Fulfillment has no browser supplier key.');
            }

            $attempt = $this->automationService->nextReconcileAttemptNumber($locked);

            $run = FulfillmentAutomationRun::query()->create([
                'fulfillment_id' => $locked->id,
                'supplier_key' => $supplierKey,
                'status' => FulfillmentAutomationRunStatus::Reserved,
                'attempt' => $attempt,
                'idempotency_key' => $this->automationService->buildReconcileIdempotencyKey($locked->id, $attempt),
            ]);

            app(AppendFulfillmentLog::class)->handle(
                $locked,
                FulfillmentLogLevel::Info,
                'Automation reconcile run reserved',
                [
                    'action' => 'automation_reconcile_reserved',
                    'run_uuid' => $run->uuid,
                    'attempt' => $attempt,
                    'supplier_order_id' => data_get($locked->meta, 'automation.supplier_order_id'),
                ],
            );

            app(BroadcastAutomationRunChanged::class)->handle(
                $run->uuid,
                'reconcile_reserved',
                $run->status->value,
            );

            return $run;
        });
    }
}
