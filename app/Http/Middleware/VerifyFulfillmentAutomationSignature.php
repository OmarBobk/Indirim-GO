<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\FulfillmentAutomationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyFulfillmentAutomationSignature
{
    public function __construct(
        private readonly FulfillmentAutomationService $automationService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->automationService->isEnabled()) {
            abort(404);
        }

        $signature = (string) $request->header('X-Automation-Signature', '');
        $timestamp = (string) $request->header('X-Automation-Timestamp', '');
        $rawBody = $request->getContent();

        if (! $this->automationService->verifySignature($rawBody, $signature, $timestamp)) {
            abort(401, 'Invalid automation signature.');
        }

        return $next($request);
    }
}
