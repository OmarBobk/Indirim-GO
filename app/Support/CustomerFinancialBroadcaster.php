<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CustomerFinancialInvalidationReason;
use App\Events\CustomerFinancialStateChanged;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class CustomerFinancialBroadcaster
{
    public static function dispatch(int|User $user, CustomerFinancialInvalidationReason $reason): void
    {
        $userId = $user instanceof User ? (int) $user->id : $user;

        if ($userId <= 0) {
            return;
        }

        $callback = static function () use ($userId, $reason): void {
            try {
                event(new CustomerFinancialStateChanged($userId, $reason));
            } catch (\Throwable $exception) {
                Log::warning('Customer financial invalidation broadcast failed', [
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
