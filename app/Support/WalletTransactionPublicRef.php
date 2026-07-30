<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;

/**
 * Generates immutable customer-facing wallet transaction references (WTX-XXXXXXXXXX).
 */
final class WalletTransactionPublicRef
{
    public const PREFIX = 'WTX-';

    public const BODY_BYTES = 5;

    public static function generate(): string
    {
        return self::PREFIX.strtoupper(bin2hex(random_bytes(self::BODY_BYTES)));
    }

    /**
     * Allocate a unique public_ref, retrying briefly on rare collisions.
     */
    public static function allocateUnique(int $attempts = 5): string
    {
        $attempts = max(1, $attempts);

        for ($i = 0; $i < $attempts; $i++) {
            $candidate = self::generate();

            if (! WalletTransaction::query()->where('public_ref', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique wallet transaction public reference.');
    }

    public static function isValidFormat(string $value): bool
    {
        return (bool) preg_match('/^WTX-[A-F0-9]{10}$/', strtoupper(trim($value)));
    }

    public static function normalize(string $value): string
    {
        return strtoupper(trim($value));
    }

    /**
     * @param  callable(string): mixed  $persist
     */
    public static function withUniqueRetry(callable $persist, int $attempts = 5): mixed
    {
        $attempts = max(1, $attempts);
        $lastException = null;

        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $persist(self::generate());
            } catch (QueryException $exception) {
                $lastException = $exception;

                if (! self::isUniquePublicRefConstraint($exception)) {
                    throw $exception;
                }
            }
        }

        throw $lastException ?? new \RuntimeException('Unable to persist wallet transaction public reference.');
    }

    public static function isUniquePublicRefConstraint(QueryException $exception): bool
    {
        $message = strtolower($exception->getMessage());

        return str_contains($message, 'public_ref')
            && (
                str_contains($message, 'unique')
                || str_contains($message, 'duplicate')
            );
    }
}
