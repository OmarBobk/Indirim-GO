<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\FulfillmentAutomationRun;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class FulfillmentAutomationArtifactController extends Controller
{
    public function show(Request $request, FulfillmentAutomationRun $run): Response
    {
        Gate::authorize('view', $run);

        $path = $this->resolveArtifactPath($request, $run);

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404);
        }

        $headers = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'png' => ['Content-Type' => 'image/png'],
            'jpg', 'jpeg' => ['Content-Type' => 'image/jpeg'],
            'webp' => ['Content-Type' => 'image/webp'],
            default => [],
        };

        return Storage::disk('local')->response($path, basename($path), $headers);
    }

    private function resolveArtifactPath(Request $request, FulfillmentAutomationRun $run): ?string
    {
        $paths = $run->artifactPaths();

        if ($request->query->has('index')) {
            $index = (int) $request->query('index');

            return $paths[$index] ?? null;
        }

        $path = (string) $request->query('path', '');

        if ($path === '' || ! in_array($path, $paths, true)) {
            return null;
        }

        return $path;
    }
}
