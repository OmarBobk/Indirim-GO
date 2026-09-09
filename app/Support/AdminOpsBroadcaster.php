<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\AdminOpsInboxChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdminOpsBroadcaster
{
    public static function dispatch(?string $reason = null): void
    {
        $callback = static function () use ($reason): void {
            try {
                event(new AdminOpsInboxChanged($reason));
            } catch (Throwable $exception) {
                Log::warning('Admin ops inbox broadcast failed', [
                    'error_id' => 'admin_ops_broadcast_failed',
                    'reason' => $reason,
                    'exception_class' => $exception::class,
                ]);
            }
        };

        if (DB::transactionLevel() > 0) {
            DB::afterCommit($callback);

            return;
        }

        $callback();
    }
}
