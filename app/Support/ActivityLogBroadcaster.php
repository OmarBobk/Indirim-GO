<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\ActivityLogChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class ActivityLogBroadcaster
{
    public static function dispatchCreated(?int $activityId): void
    {
        $callback = static function () use ($activityId): void {
            try {
                event(new ActivityLogChanged($activityId));
            } catch (\Throwable $exception) {
                // Optional realtime only. Durable activity rows are already persisted.
                // Never log the raw exception message: Pusher/Reverb errors may include
                // signed query parameters.
                Log::warning('Activity log broadcast failed', [
                    'error_id' => 'activity_log_broadcast_failed',
                    'activity_id' => $activityId,
                    'exception_class' => $exception::class,
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);
        } else {
            $callback();
        }
    }
}
