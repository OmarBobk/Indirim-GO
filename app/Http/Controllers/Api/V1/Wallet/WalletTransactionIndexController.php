<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Actions\MobileWallet\ListMobileWalletTransactions;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Wallet\ListWalletPageRequest;
use Illuminate\Http\JsonResponse;

class WalletTransactionIndexController extends Controller
{
    public function __invoke(ListWalletPageRequest $request, ListMobileWalletTransactions $action): JsonResponse
    {
        return response()
            ->json($action->handle(
                $request->user(),
                $request->pageNumber(),
                $request->perPage(),
            ))
            ->header('Cache-Control', 'private, no-store');
    }
}
