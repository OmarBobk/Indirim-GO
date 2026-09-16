<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Actions\MobileWallet\GetMobileTopupDetail;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TopupShowController extends Controller
{
    public function __invoke(Request $request, string $public_ref, GetMobileTopupDetail $action): JsonResponse
    {
        return response()
            ->json($action->handle($request->user(), $public_ref))
            ->header('Cache-Control', 'private, no-store');
    }
}
