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
    /**
     * @param  CustomerFinancialInvalidationReason|list<CustomerFinancialInvalidationReason>  $reasons
     */
    public static function dispatch(
        int|User $user,
        CustomerFinancialInvalidationReason|array $reasons,
    ): void {
        $reasons = $reasons instanceof CustomerFinancialInvalidationReason ? [$reasons] : $reasons;
        $reasons = array_values(array_reduce(
            $reasons,
            static function (array $carry, mixed $reason): array {
                if ($reason instanceof CustomerFinancialInvalidationReason) {
                    $carry[$reason->value] = $reason;
                }

                return $carry;
            },
            [],
        ));

        $userId = $user instanceof User ? (int) $user->id : $user;

        if ($userId <= 0 || $reasons === []) {
            return;
        }

        $callback = static function () use ($userId, $reasons): void {
            try {
                event(new CustomerFinancialStateChanged($userId, $reasons));
            } catch (\Throwable $exception) {
                Log::warning('Customer financial invalidation broadcast failed', [
                    'user_id' => $userId,
                    'reasons' => array_map(
                        static fn (CustomerFinancialInvalidationReason $reason): string => $reason->value,
                        $reasons,
                    ),
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
