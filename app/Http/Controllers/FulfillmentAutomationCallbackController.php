<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Fulfillments\IngestFulfillmentAutomationProgress;
use App\Actions\Fulfillments\IngestFulfillmentAutomationResult;
use App\Actions\Fulfillments\StoreFulfillmentAutomationArtifact;
use App\DTOs\Automation\AutomationProgressPayloadDTO;
use App\Enums\FulfillmentAutomationProgressStep;
use App\Models\FulfillmentAutomationRun;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Throwable;

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
        } catch (Throwable $exception) {
            Log::error('Automation result ingest failed', [
                'run_uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Ingest failed.'], 422);
        }

        return response()->json(['status' => 'accepted']);
    }

    public function progress(
        Request $request,
        string $uuid,
        IngestFulfillmentAutomationProgress $ingest,
    ): JsonResponse {
        $run = FulfillmentAutomationRun::query()->where('uuid', $uuid)->firstOrFail();

        $maxBytes = (int) config('fulfillment_automation.progress.max_payload_bytes', 8192);

        if (strlen($request->getContent()) > $maxBytes) {
            return response()->json(['message' => 'Payload too large.'], 413);
        }

        $validated = $request->validate([
            'progress_sequence' => ['required_without:sequence', 'integer', 'min:1'],
            'sequence' => ['required_without:progress_sequence', 'integer', 'min:1'],
            'phase' => ['required', 'string', Rule::in(['purchase', 'reconcile'])],
            'step' => ['required', 'string', Rule::in(FulfillmentAutomationProgressStep::values())],
            'emitted_at' => ['required', 'string', 'max:64'],
            'heartbeat' => ['sometimes', 'boolean'],
            'safe_message_code' => ['nullable', 'string', 'max:100'],
            'safe_params' => ['nullable', 'array', 'max:10'],
            'worker_instance_id' => ['required', 'string', 'max:64'],
            'worker_build' => ['required', 'string', 'max:100'],
            'driver_name' => ['required', 'string', 'max:64'],
            'driver_version' => ['required', 'string', 'max:64'],
            'detected_ui_version' => ['nullable', 'string', 'max:64'],
            'page_contract_version' => ['nullable', 'string', 'max:64'],
            'session_alias' => ['nullable', 'string', 'max:64'],
        ]);

        if (isset($validated['safe_params']) && is_array($validated['safe_params'])) {
            foreach ($validated['safe_params'] as $key => $value) {
                if (! is_string($key) || (! is_scalar($value) && $value !== null)) {
                    return response()->json([
                        'message' => 'Invalid progress payload.',
                        'errors' => ['safe_params' => ['safe_params values must be scalar.']],
                    ], 422);
                }
            }
        }

        $payload = AutomationProgressPayloadDTO::fromValidated($validated);

        try {
            $result = $ingest->handle($run, $payload);
        } catch (Throwable $exception) {
            Log::error('Automation progress ingest failed', [
                'run_uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Ingest failed.'], 422);
        }

        return response()->json($result);
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
