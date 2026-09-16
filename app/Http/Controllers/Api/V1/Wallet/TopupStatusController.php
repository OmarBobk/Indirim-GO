<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Actions\MobileWallet\GetMobileTopupStatus;
use App\Http\Controllers\Controller;
use App\Support\Api\V1\MobileIdempotencyKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopupStatusController extends Controller
{
    public function __invoke(Request $request, GetMobileTopupStatus $action): JsonResponse
    {
        $rawKey = MobileIdempotencyKey::requireFrom($request);
        $result = $action->handle($request->user(), $rawKey);

        return response()
            ->json(['data' => $result['data']], $result['status'])
            ->header('Cache-Control', 'private, no-store');
    }
}
