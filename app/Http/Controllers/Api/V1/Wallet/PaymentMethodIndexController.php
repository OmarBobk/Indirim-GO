<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Actions\MobileWallet\ListMobilePaymentMethods;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PaymentMethodIndexController extends Controller
{
    public function __invoke(Request $request, ListMobilePaymentMethods $action): JsonResponse
    {
        return response()
            ->json($action->handle($request->user()))
            ->header('Cache-Control', 'private, no-store');
    }
}
