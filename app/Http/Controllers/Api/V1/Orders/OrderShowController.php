<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Orders;

use App\Actions\MobilePurchase\GetMobileOrderReceipt;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class OrderShowController extends Controller
{
    public function __invoke(Request $request, string $order_number, GetMobileOrderReceipt $action): JsonResponse
    {
        return response()
            ->json($action->handle($request->user(), $order_number))
            ->header('Cache-Control', 'private, no-store');
    }
}
