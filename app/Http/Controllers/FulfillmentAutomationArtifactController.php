<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FulfillmentAutomationRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FulfillmentAutomationArtifactController extends Controller
{
    public function show(Request $request, FulfillmentAutomationRun $run): Response
    {
        $this->authorize('view', $run);

        $path = (string) $request->query('path', '');

        if ($path === '' || ! in_array($path, $run->artifactPaths(), true)) {
            abort(404);
        }

        if (! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        return Storage::disk('local')->response($path, basename($path));
    }
}
