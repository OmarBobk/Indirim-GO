<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\FulfillmentAutomationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyFulfillmentAutomationArtifactSignature
{
    public function __construct(
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->automationService->isEnabled()) {
            abort(404);
        }

        $runUuid = (string) $request->route('uuid', '');

        if ($runUuid === '') {
            abort(401, 'Invalid automation signature.');
        }

        $file = $request->file('file');

        if ($file === null) {
            abort(401, 'Invalid automation signature.');
        }

        $label = (string) $request->input('label', 'screenshot');
        $fileContents = file_get_contents($file->getRealPath() ?: $file->getPathname());
        $fileHash = hash('sha256', $fileContents !== false ? $fileContents : '');

        if ($fileHash === '') {
            abort(401, 'Invalid automation signature.');
        }

        $signature = (string) $request->header('X-Automation-Signature', '');
        $timestamp = (string) $request->header('X-Automation-Timestamp', '');

        if (! $this->automationService->verifyArtifactSignature(
            $runUuid,
            $label,
            $fileHash,
            $signature,
            $timestamp,
        )) {
            abort(401, 'Invalid automation signature.');
        }

        return $next($request);
    }
}
