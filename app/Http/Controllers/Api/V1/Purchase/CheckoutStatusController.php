<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Actions\MobilePurchase\GetMobileCheckoutStatus;
use App\Http\Controllers\Controller;
use App\Support\Api\V1\MobileIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutStatusController extends Controller
{
    public function __invoke(Request $request, GetMobileCheckoutStatus $action): JsonResponse
    {
        $rawKey = MobileIdempotencyKey::requireFrom($request);
        $result = $action->handle($request->user(), $rawKey);

        return response()
            ->json(['data' => $result['data']], $result['status'])
            ->header('Cache-Control', 'private, no-store');
    }
}
