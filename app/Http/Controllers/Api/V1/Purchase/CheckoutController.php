<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Actions\MobilePurchase\ExecuteMobileCheckout;
use App\Exceptions\MobileApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchase\CheckoutRequest;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __invoke(CheckoutRequest $request, ExecuteMobileCheckout $action): JsonResponse
    {
        $rawKey = $this->requireIdempotencyKey($request);

        $result = $action->handle(
            $request->user(),
            $request->item(),
            $request->quoteFingerprint(),
            $rawKey,
        );

        return response()
            ->json(['data' => $result['data']], $result['status'])
            ->header('Cache-Control', 'private, no-store');
    }

    private function requireIdempotencyKey(CheckoutRequest $request): string
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
        $max = max(16, (int) config('mobile_api.checkout.idempotency_key_max_length', 128));

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
