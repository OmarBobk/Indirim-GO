<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Actions\MobilePurchase\ExecuteMobileCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchase\CheckoutRequest;
use App\Support\Api\V1\MobileIdempotencyKey;
use Illuminate\Http\JsonResponse;

class CheckoutController extends Controller
{
    public function __invoke(CheckoutRequest $request, ExecuteMobileCheckout $action): JsonResponse
    {
        $rawKey = MobileIdempotencyKey::requireFrom($request);

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
}
