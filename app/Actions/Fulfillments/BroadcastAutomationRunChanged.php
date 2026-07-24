<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Events\AutomationRunChanged;
use Illuminate\Support\Facades\DB;

class BroadcastAutomationRunChanged
{
    public function handle(
        ?string $runUuid = null,
        ?string $type = null,
        ?string $status = null,
    ): void {
        $broadcast = static function () use ($runUuid, $type, $status): void {
            event(new AutomationRunChanged($runUuid, $type, $status));
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($broadcast);

            return;
        }

        $broadcast();
    }
}
