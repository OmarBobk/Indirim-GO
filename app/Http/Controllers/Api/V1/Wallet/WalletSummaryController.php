<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Actions\MobilePurchase\GetMobileWalletSummary;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletSummaryController extends Controller
{
    public function __invoke(Request $request, GetMobileWalletSummary $action): JsonResponse
    {
        return response()
            ->json($action->handle($request->user()))
            ->header('Cache-Control', 'private, no-store');
    }
}
