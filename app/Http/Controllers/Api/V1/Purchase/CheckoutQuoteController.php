<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Purchase;

use App\Actions\MobilePurchase\QuoteMobileCheckout;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Purchase\CheckoutQuoteRequest;
use Illuminate\Http\JsonResponse;

class CheckoutQuoteController extends Controller
{
    public function __invoke(CheckoutQuoteRequest $request, QuoteMobileCheckout $action): JsonResponse
    {
        return response()
            ->json($action->handle($request->user(), $request->item()))
            ->header('Cache-Control', 'private, no-store');
    }
}
