<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Wallet;

use App\Actions\MobileWallet\StreamMobileTopupProof;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TopupProofController extends Controller
{
    public function __invoke(Request $request, string $public_ref, StreamMobileTopupProof $action): Response
    {
        return $action->handle($request->user(), $public_ref);
    }
}
