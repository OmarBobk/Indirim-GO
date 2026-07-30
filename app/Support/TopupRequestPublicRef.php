<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\TopupRequest;
use Illuminate\Database\QueryException;

/**
 * Generates immutable customer-facing top-up references (TUP-XXXXXXXXXX).
 */
final class TopupRequestPublicRef
{
    public const PREFIX = 'TUP-';

    public const BODY_BYTES = 5;

    public static function generate(): string
    {
        return self::PREFIX.strtoupper(bin2hex(random_bytes(self::BODY_BYTES)));
    }

    public static function allocateUnique(int $attempts = 5): string
    {
        $attempts = max(1, $attempts);

        for ($i = 0; $i < $attempts; $i++) {
            $candidate = self::generate();

            if (! TopupRequest::query()->where('public_ref', $candidate)->exists()) {
                return $candidate;
            }
        }

        throw new \RuntimeException('Unable to allocate a unique top-up public reference.');
    }

    public static function isValidFormat(string $value): bool
    {
        return (bool) preg_match('/^TUP-[A-F0-9]{10}$/', strtoupper(trim($value)));
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

        throw $lastException ?? new \RuntimeException('Unable to persist top-up public reference.');
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
