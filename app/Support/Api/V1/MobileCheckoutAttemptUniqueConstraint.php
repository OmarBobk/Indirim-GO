<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use Illuminate\Database\QueryException;

/**
 * Detects the mobile_checkout_attempts unique (user_id, key_hash) race only.
 */
final class MobileCheckoutAttemptUniqueConstraint
{
    public const INDEX_NAME = 'mobile_checkout_attempts_user_id_key_hash_unique';

    public static function matches(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = $exception->getMessage();

        // MySQL/MariaDB: require SQLSTATE, ER_DUP_ENTRY, and the exact unique index name.
        // Do not accept generic "Duplicate entry" text that merely mentions column-like words.
        if ($sqlState === '23000' && $driverCode === 1062) {
            return str_contains($message, self::INDEX_NAME);
        }

        // SQLite (feature tests): UNIQUE constraint failed on the composite columns.
        if (str_contains($message, 'UNIQUE constraint failed')
            && str_contains($message, 'mobile_checkout_attempts')
            && str_contains($message, 'user_id')
            && str_contains($message, 'key_hash')) {
            return true;
        }

        return false;
    }
}
