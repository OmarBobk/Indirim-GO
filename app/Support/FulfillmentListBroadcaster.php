<?php

declare(strict_types=1);

namespace App\Support;

use App\Events\FulfillmentListChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Optional realtime publication for fulfillment inbox/list changes.
 * Transport failures must never reverse durable fulfillment/automation state.
 */
final class FulfillmentListBroadcaster
{
    public static function dispatch(int $fulfillmentId, string $type): void
    {
        $callback = static function () use ($fulfillmentId, $type): void {
            try {
                event(new FulfillmentListChanged($fulfillmentId, $type));
            } catch (Throwable $exception) {
                Log::warning('Fulfillment list broadcast failed', [
                    'error_id' => 'fulfillment_list_broadcast_failed',
                    'fulfillment_id' => $fulfillmentId,
                    'type' => $type,
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
