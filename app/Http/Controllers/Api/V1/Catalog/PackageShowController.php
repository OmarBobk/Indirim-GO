<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\MobileCatalog\ShowMobilePackage;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PackageShowController extends Controller
{
    public function __invoke(Request $request, int $package, ShowMobilePackage $action): JsonResponse
    {
        $payload = $action->handle($request->user(), $package);

        return response()
            ->json($payload)
            ->header('Cache-Control', 'private, no-store');
    }
}
