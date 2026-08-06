<?php

declare(strict_types=1);

namespace App\Support\Automation;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Persists the latest Wasim health probe snapshot for the admin dashboard (C1.2).
 *
 * Read-only with respect to fulfillments/orders — this store only ever holds a
 * cached copy of the worker's self-reported probe result.
 *
 * @phpstan-type WasimProbeResult array{
 *     checked_at: string,
 *     worker_build: ?string,
 *     worker_instance_id: ?string,
 *     driver_version: ?string,
 *     detected_ui_version: ?string,
 *     purchase_contract_version: ?string,
 *     orders_contract_version: ?string,
 *     session_state: ?string,
 *     purchase_contract_state: ?string,
 *     reconcile_contract_state: ?string,
 *     test_product_state: ?string,
 *     state: string,
 *     failure_codes: list<string>,
 *     duration_ms: ?int,
 *     operational_classification: ?string,
 * }
 * @phpstan-type WasimProbeSnapshot array{
 *     last_result: WasimProbeResult,
 *     previous_healthy: ?bool,
 *     consecutive_failure_count: int,
 *     last_healthy_at: ?string,
 * }
 */
final class WasimHealthProbeStore
{
    private const CACHE_KEY = 'fulfillment-automation:wasim-probe.v1';

    /**
     * @return WasimProbeSnapshot|null
     */
    public function get(): ?array
    {
        $snapshot = Cache::get(self::CACHE_KEY);

        return is_array($snapshot) ? $snapshot : null;
    }

    /**
     * Whether the last stored probe is recent enough to skip a fresh worker call.
     */
    public function isFresh(int $withinSeconds): bool
    {
        if ($withinSeconds <= 0) {
            return false;
        }

        $checkedAt = $this->get()['last_result']['checked_at'] ?? null;

        if (! is_string($checkedAt) || $checkedAt === '') {
            return false;
        }

        try {
            return Carbon::parse($checkedAt)->diffInSeconds(now(), true) < $withinSeconds;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  WasimProbeResult  $result
     * @return WasimProbeSnapshot
     */
    public function record(array $result): array
    {
        $previous = $this->get();
        $isHealthy = ($result['state'] ?? null) === 'healthy';
        $previousWasHealthy = ($previous['last_result']['state'] ?? null) === 'healthy';

        $snapshot = [
            'last_result' => $result,
            'previous_healthy' => $previous !== null ? $previousWasHealthy : null,
            'consecutive_failure_count' => $isHealthy
                ? 0
                : ((int) ($previous['consecutive_failure_count'] ?? 0) + 1),
            'last_healthy_at' => $isHealthy
                ? (string) ($result['checked_at'] ?? now()->toIso8601String())
                : ($previous['last_healthy_at'] ?? null),
        ];

        Cache::forever(self::CACHE_KEY, $snapshot);

        return $snapshot;
    }
}
