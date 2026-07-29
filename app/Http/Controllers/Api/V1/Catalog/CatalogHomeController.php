<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\MobileCatalog\BuildCatalogHome;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogHomeController extends Controller
{
    public function __invoke(Request $request, BuildCatalogHome $action): JsonResponse
    {
        $payload = $action->handle($request->user());

        return response()
            ->json($payload)
            ->header('Cache-Control', 'private, no-store');
    }
}
