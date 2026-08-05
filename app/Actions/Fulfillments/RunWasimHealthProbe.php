<?php

declare(strict_types=1);

namespace App\Actions\Fulfillments;

use App\Enums\WasimHealthProbeState;
use App\Services\FulfillmentAutomationService;
use App\Support\Automation\WasimHealthProbeStore;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Runs the worker's Wasim health probe (C1.2), a read-only session/UI/contract
 * check that never mutates fulfillments, orders, or the automation kill switch.
 *
 * @phpstan-import-type WasimProbeResult from WasimHealthProbeStore
 * @phpstan-import-type WasimProbeSnapshot from WasimHealthProbeStore
 */
final class RunWasimHealthProbe
{
    public function __construct(
        private readonly FulfillmentAutomationService $automationService,
        private readonly WasimHealthProbeStore $store,
    ) {}

    /**
     * @return WasimProbeSnapshot
     */
    public function handle(bool $force = false): array
    {
        /** @var array<string, mixed> $probeConfig */
        $probeConfig = config('fulfillment_automation.wasim_probe', []);

        if (! (bool) ($probeConfig['enabled'] ?? true)) {
            return $this->finish($this->localResult(WasimHealthProbeState::NotConfigured, ['probe_disabled']));
        }

        $cacheSeconds = max(0, (int) ($probeConfig['cache_seconds'] ?? 60));

        if (! $force && $this->store->isFresh($cacheSeconds)) {
            /** @var WasimProbeSnapshot $cached */
            $cached = $this->store->get();

            return $cached;
        }

        $workerUrl = rtrim((string) config('fulfillment_automation.worker_url'), '/');
        $secret = (string) config('fulfillment_automation.callback_secret');

        if ($workerUrl === '' || $secret === '') {
            return $this->finish($this->localResult(WasimHealthProbeState::NotConfigured, ['worker_not_configured']));
        }

        $supplier = $this->automationService->supplierConfig('wasim') ?? [];
        $sessionKey = trim((string) ($supplier['session_key'] ?? 'wasim-main'));
        $credentials = is_array($supplier['credentials'] ?? null) ? $supplier['credentials'] : [];

        if ($sessionKey === '') {
            return $this->finish($this->localResult(WasimHealthProbeState::NotConfigured, ['session_key_missing']));
        }

        $body = [
            'session_key' => $sessionKey,
            'credentials' => $credentials,
            'test_product' => $this->buildTestProduct($probeConfig),
            'mode' => 'full',
        ];

        $rawBody = json_encode($body, JSON_THROW_ON_ERROR);
        $timestamp = time();
        $signature = $this->automationService->signPayload($rawBody, $timestamp);
        $timeoutSeconds = max(1, (int) ($probeConfig['timeout_seconds'] ?? 90));

        try {
            $response = Http::timeout($timeoutSeconds)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Automation-Timestamp' => (string) $timestamp,
                    'X-Automation-Signature' => $signature,
                ])
                ->withBody($rawBody, 'application/json')
                ->post($workerUrl.'/v1/suppliers/wasim/probe');
        } catch (ConnectionException) {
            return $this->finish($this->localResult(WasimHealthProbeState::Unreachable, ['connection_failed']));
        } catch (Throwable) {
            return $this->finish($this->localResult(WasimHealthProbeState::Unreachable, ['probe_request_failed']));
        }

        $result = match (true) {
            $response->status() === 401 => $this->localResult(WasimHealthProbeState::Unreachable, ['hmac_unauthorized']),
            $response->status() === 409 => $this->localResult(WasimHealthProbeState::Unreachable, ['probe_busy']),
            $response->status() === 429 => $this->localResult(WasimHealthProbeState::Unreachable, ['rate_limited']),
            ! $response->successful() => $this->localResult(WasimHealthProbeState::Unreachable, ['http_'.$response->status()]),
            default => $this->sanitizeResponse($response->json()),
        };

        return $this->finish($result);
    }

    /**
     * @param  WasimProbeResult  $result
     * @return WasimProbeSnapshot
     */
    private function finish(array $result): array
    {
        $snapshot = $this->store->record($result);

        app(ObserveAutomationSafetySignal::class)->handleHealthyProbe('wasim', $result);

        app(BroadcastAutomationRunChanged::class)->handle(null, 'wasim_probe', null);

        return $snapshot;
    }

    /**
     * @param  array<string, mixed>  $probeConfig
     * @return array<string, mixed>|null
     */
    private function buildTestProduct(array $probeConfig): ?array
    {
        $productApi = $probeConfig['test_product_api'] ?? null;

        if (! is_string($productApi) || trim($productApi) === '') {
            return null;
        }

        return array_filter([
            'product_api' => trim($productApi),
            'expected_product_id' => $probeConfig['expected_product_id'] ?? null,
            'expected_currency' => $probeConfig['expected_currency'] ?? null,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    /**
     * Validates and narrows the worker's response to a safe, typed allowlist.
     *
     * @return WasimProbeResult
     */
    private function sanitizeResponse(mixed $json): array
    {
        if (! is_array($json)) {
            return $this->localResult(WasimHealthProbeState::ContractFailed, ['malformed_response']);
        }

        $state = is_string($json['state'] ?? null) ? $json['state'] : null;

        if ($state === null || ! in_array($state, WasimHealthProbeState::values(), true)) {
            return $this->localResult(WasimHealthProbeState::ContractFailed, ['invalid_state_field']);
        }

        $failureCodes = [];

        if (is_array($json['failure_codes'] ?? null)) {
            foreach ($json['failure_codes'] as $code) {
                if (is_string($code) && trim($code) !== '') {
                    $failureCodes[] = trim($code);
                }
            }
        }

        return [
            'checked_at' => is_string($json['checked_at'] ?? null) && $json['checked_at'] !== ''
                ? $json['checked_at']
                : now()->toIso8601String(),
            'worker_build' => $this->nullableString($json['worker_build'] ?? null),
            'worker_instance_id' => $this->nullableString($json['worker_instance_id'] ?? null),
            'driver_version' => $this->nullableString($json['driver_version'] ?? null),
            'detected_ui_version' => $this->nullableString($json['detected_ui_version'] ?? null),
            'purchase_contract_version' => $this->nullableString($json['purchase_contract_version'] ?? null),
            'orders_contract_version' => $this->nullableString($json['orders_contract_version'] ?? null),
            'session_state' => $this->nullableString($json['session_state'] ?? null),
            'purchase_contract_state' => $this->nullableString($json['purchase_contract_state'] ?? null),
            'reconcile_contract_state' => $this->nullableString($json['reconcile_contract_state'] ?? null),
            'test_product_state' => $this->nullableString($json['test_product_state'] ?? null),
            'state' => $state,
            'failure_codes' => $failureCodes,
            'duration_ms' => is_numeric($json['duration_ms'] ?? null) ? (int) $json['duration_ms'] : null,
            'operational_classification' => $this->nullableString($json['operational_classification'] ?? null),
        ];
    }

    /**
     * @return WasimProbeResult
     */
    private function localResult(WasimHealthProbeState $state, array $failureCodes = []): array
    {
        return [
            'checked_at' => now()->toIso8601String(),
            'worker_build' => null,
            'worker_instance_id' => null,
            'driver_version' => null,
            'detected_ui_version' => null,
            'purchase_contract_version' => null,
            'orders_contract_version' => null,
            'session_state' => null,
            'purchase_contract_state' => null,
            'reconcile_contract_state' => null,
            'test_product_state' => null,
            'state' => $state->value,
            'failure_codes' => $failureCodes,
            'duration_ms' => null,
            'operational_classification' => null,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
