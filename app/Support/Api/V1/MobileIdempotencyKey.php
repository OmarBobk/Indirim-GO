<?php

declare(strict_types=1);

namespace App\Support\Api\V1;

use App\Exceptions\MobileApiException;
use Illuminate\Http\Request;

/**
 * Shared Idempotency-Key header validation for checkout and status.
 * Never returns or logs the raw key.
 */
final class MobileIdempotencyKey
{
    public static function requireFrom(Request $request): string
    {
        $header = (string) config('mobile_api.checkout.idempotency_header', 'Idempotency-Key');
        $raw = $request->headers->get($header);

        if (! is_string($raw) || trim($raw) === '') {
            throw new MobileApiException(
                'messages.mobile_api.idempotency_key_required',
                'idempotency_key_required',
                422,
            );
        }

        $raw = trim($raw);
        $max = max(1, (int) config('mobile_api.checkout.idempotency_key_max_length', 128));

        if (strlen($raw) > $max) {
            throw new MobileApiException(
                'messages.mobile_api.idempotency_key_invalid',
                'idempotency_key_invalid',
                422,
            );
        }

        return $raw;
    }
}
