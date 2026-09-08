<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Orders;

use App\Actions\MobileOrders\ListMobileOrders;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListOrdersRequest;
use Illuminate\Http\JsonResponse;

class OrderIndexController extends Controller
{
    public function __invoke(ListOrdersRequest $request, ListMobileOrders $action): JsonResponse
    {
        return response()
            ->json($action->handle(
                $request->user(),
                $request->pageNumber(),
                $request->perPage(),
                $request->searchQuery(),
                $request->customerState(),
            ))
            ->header('Cache-Control', 'private, no-store');
    }
}
