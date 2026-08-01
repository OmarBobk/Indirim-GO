<?php

declare(strict_types=1);

use App\Support\Api\V1\MobileCheckoutAttemptUniqueConstraint;
use Illuminate\Database\QueryException;

function m31QueryException(string $sqlState, int $driverCode, string $message, string $sql = 'insert'): QueryException
{
    $pdo = new \PDOException($message, 0);
    $pdo->errorInfo = [$sqlState, $driverCode, $message];

    return new QueryException('mysql', $sql, [], $pdo);
}

test('matches mysql duplicate on mobile_checkout_attempts user_id key_hash unique index', function (): void {
    $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-abc' for key 'mobile_checkout_attempts_user_id_key_hash_unique'";

    expect(MobileCheckoutAttemptUniqueConstraint::matches(
        m31QueryException('23000', 1062, $message)
    ))->toBeTrue();
});

test('rejects mysql duplicate entry that only mentions column-name words', function (): void {
    $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry 'x' for key 'orders_user_id_unique'";

    expect(MobileCheckoutAttemptUniqueConstraint::matches(
        m31QueryException('23000', 1062, $message)
    ))->toBeFalse();
});

test('rejects mysql duplicate without driver code 1062', function (): void {
    $message = "SQLSTATE[23000]: Integrity constraint violation: Duplicate entry '1-abc' for key 'mobile_checkout_attempts_user_id_key_hash_unique'";

    expect(MobileCheckoutAttemptUniqueConstraint::matches(
        m31QueryException('23000', 0, $message)
    ))->toBeFalse();
});

test('rejects unrelated unique constraint on another table mentioning user_id and key_hash words', function (): void {
    $message = "SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '1-hash' for key 'api_tokens_user_id_key_hash_unique'";

    expect(MobileCheckoutAttemptUniqueConstraint::matches(
        m31QueryException('23000', 1062, $message)
    ))->toBeFalse();
});

test('matches sqlite unique constraint failed on mobile_checkout_attempts user_id key_hash', function (): void {
    $message = 'UNIQUE constraint failed: mobile_checkout_attempts.user_id, mobile_checkout_attempts.key_hash';

    expect(MobileCheckoutAttemptUniqueConstraint::matches(
        m31QueryException('23000', 19, $message)
    ))->toBeTrue();
});
