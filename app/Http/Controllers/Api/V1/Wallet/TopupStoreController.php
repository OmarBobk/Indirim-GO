<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Actions\MobileWallet\SubmitMobileTopup;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Wallet\SubmitMobileTopupRequest;
use App\Support\Api\V1\MobileIdempotencyKey;
use Illuminate\Http\JsonResponse;

class TopupStoreController extends Controller
{
    public function __invoke(SubmitMobileTopupRequest $request, SubmitMobileTopup $action): JsonResponse
    {
        $rawKey = MobileIdempotencyKey::requireFrom($request);
        $result = $action->handle(
            $request->user(),
            $request->amount(),
            $request->currency(),
            $request->paymentMethodId(),
            $request->proofFile(),
            $rawKey,
        );

        return response()
            ->json(['data' => $result['data']], $result['status'])
            ->header('Cache-Control', 'private, no-store');
    }
}
