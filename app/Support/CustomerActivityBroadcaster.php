<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CustomerActivityInvalidationReason;
use App\Events\CustomerActivityInvalidated;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CustomerActivityBroadcaster
{
    public static function dispatch(int|User $user, CustomerActivityInvalidationReason $reason): void
    {
        $userId = $user instanceof User ? (int) $user->id : $user;

        if ($userId <= 0) {
            return;
        }

        $callback = static function () use ($userId, $reason): void {
            try {
                event(new CustomerActivityInvalidated($userId, $reason));
            } catch (\Throwable $exception) {
                Log::warning('Customer activity invalidation broadcast failed', [
                    'user_id' => $userId,
                    'reason' => $reason->value,
                    'message' => $exception->getMessage(),
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
