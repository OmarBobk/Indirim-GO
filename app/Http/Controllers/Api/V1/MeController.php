<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\MobileUserResource;
use Illuminate\Http\Request;

class MeController extends Controller
{
    public function __invoke(Request $request): MobileUserResource
    {
        return new MobileUserResource($request->user());
    }
}
