<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use Illuminate\Database\QueryException;

/**
 * Detects the mobile_topup_attempts unique (user_id, key_hash) race only.
 */
final class MobileTopupAttemptUniqueConstraint
{
    public const INDEX_NAME = 'mobile_topup_attempts_user_id_key_hash_unique';

    public static function matches(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        if ($sqlState === '23000' && $driverCode === 1062) {
            return str_contains($message, self::INDEX_NAME);
        }

        if (str_contains($message, 'UNIQUE constraint failed')
            && str_contains($message, 'mobile_topup_attempts')
            && str_contains($message, 'user_id')
            && str_contains($message, 'key_hash')) {
            return true;
        }

        return false;
    }
}
