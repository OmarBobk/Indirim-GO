<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\SupplierPrices\IngestSupplierPriceScanResult;
use App\Models\SupplierPriceScan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SupplierPriceScanCallbackController extends Controller
{
    public function result(
        Request $request,
        string $uuid,
        IngestSupplierPriceScanResult $ingest,
    ): JsonResponse {
        $scan = SupplierPriceScan::query()->where('uuid', $uuid)->firstOrFail();

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->all();

        try {
            $ingest->handle($scan, $payload);
        } catch (\Throwable $exception) {
            Log::error('Supplier price scan ingest failed', [
                'scan_uuid' => $uuid,
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Ingest failed.'], 422);
        }

        return response()->json(['status' => 'accepted']);
    }
}
