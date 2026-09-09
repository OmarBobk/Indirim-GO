<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Events\AutomationRunChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class BroadcastAutomationRunChanged
{
    public function handle(
        ?string $runUuid = null,
        ?string $type = null,
        ?string $status = null,
    ): void {
        $broadcast = static function () use ($runUuid, $type, $status): void {
            try {
                event(new AutomationRunChanged($runUuid, $type, $status));
            } catch (Throwable $exception) {
                Log::warning('Automation broadcast failed', [
                    'error_id' => 'automation_broadcast_failed',
                    'type' => $type,
                    'exception_class' => $exception::class,
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($broadcast);

            return;
        }

        $broadcast();
    }
}
