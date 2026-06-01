<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\FulfillmentAutomationRunStatus;
use App\Enums\FulfillmentLogLevel;
use App\Models\Fulfillment;
use App\Models\FulfillmentAutomationRun;
use Illuminate\Support\Facades\DB;

class CancelFulfillmentAutomationRun
{
    public function handle(Fulfillment $fulfillment, string $reason = 'cancelled'): int
    {
        return DB::transaction(function () use ($fulfillment, $reason): int {
            $locked = Fulfillment::query()
                ->whereKey($fulfillment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $runs = FulfillmentAutomationRun::query()
                ->where('fulfillment_id', $locked->id)
                ->active()
                ->lockForUpdate()
                ->get();

            $cancelled = 0;

            foreach ($runs as $run) {
                $run->fill([
                    'status' => FulfillmentAutomationRunStatus::Cancelled,
                    'finished_at' => $run->finished_at ?? now(),
                    'error_message' => $reason,
                    'meta' => array_merge($run->meta ?? [], [
                        'cancel_reason' => $reason,
                    ]),
                ])->save();

                $cancelled++;
            }

            if ($cancelled > 0) {
                app(AppendFulfillmentLog::class)->handle(
                    $locked,
                    FulfillmentLogLevel::Info,
                    'Automation run cancelled',
                    [
                        'action' => 'automation_cancelled',
                        'reason' => $reason,
                        'count' => $cancelled,
                    ],
                );
            }

            return $cancelled;
        });
    }
}
