<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fulfillments\IngestFulfillmentAutomationResult;
use App\Actions\Fulfillments\StoreFulfillmentAutomationArtifact;
use App\Models\FulfillmentAutomationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class FulfillmentAutomationCallbackController extends Controller
{
    public function result(
        Request $request,
        string $uuid,
        IngestFulfillmentAutomationResult $ingest,
    ): JsonResponse {
        $run = FulfillmentAutomationRun::query()->where('uuid', $uuid)->firstOrFail();

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        try {
            $ingest->handle($run, $payload);
        } catch (\Throwable $exception) {
            Log::error('Automation result ingest failed', [
                'run_uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Ingest failed.'], 422);
        }

        return response()->json(['status' => 'accepted']);
    }

    public function artifacts(
        Request $request,
        string $uuid,
        StoreFulfillmentAutomationArtifact $storeArtifact,
    ): JsonResponse {
        $run = FulfillmentAutomationRun::query()->where('uuid', $uuid)->firstOrFail();

        $request->validate([
            'file' => ['required', 'file', 'max:10240'],
            'label' => ['nullable', 'string', 'max:100'],
        ]);

        $path = $storeArtifact->handle(
            $run,
            $request->file('file'),
            (string) $request->input('label', 'screenshot'),
        );

        return response()->json([
            'path' => $path,
        ]);
    }
}
