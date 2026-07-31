<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Actions\MobilePurchase\GetMobileCheckoutStatus;
use App\Exceptions\MobileApiException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutStatusController extends Controller
{
    public function __invoke(Request $request, GetMobileCheckoutStatus $action): JsonResponse
    {
        $rawKey = $this->requireIdempotencyKey($request);
        $result = $action->handle($request->user(), $rawKey);

        return response()
            ->json(['data' => $result['data']], $result['status'])
            ->header('Cache-Control', 'private, no-store');
    }

    private function requireIdempotencyKey(Request $request): string
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

        return trim($raw);
    }
}
