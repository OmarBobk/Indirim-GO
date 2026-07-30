<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Catalog;

use App\Actions\MobileCatalog\ListMobilePackages;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\ListPackagesRequest;
use Illuminate\Http\JsonResponse;

class PackageIndexController extends Controller
{
    public function __invoke(ListPackagesRequest $request, ListMobilePackages $action): JsonResponse
    {
        $payload = $action->handle(
            $request->user(),
            $request->categoryId(),
            $request->searchQuery(),
            $request->pageNumber(),
            $request->perPage(),
        );

        return response()
            ->json($payload)
            ->header('Cache-Control', 'private, no-store');
    }
}
