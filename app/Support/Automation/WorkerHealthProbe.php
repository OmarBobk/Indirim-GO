<?php

declare(strict_types=1);

namespace App\Support\Automation;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Cached probe of the automation worker's `/health` endpoint (C1.1).
 *
 * @phpstan-type WorkerHealthResult array{
 *     status: string,
 *     ready: bool,
 *     build: ?string,
 *     instance_id: ?string,
 *     uptime_seconds: ?int,
 *     active_count: ?int,
 *     configured_max_concurrency: ?int,
 *     browser_available: ?bool,
 *     playwright_version: ?string,
 *     driver_versions: array<string, string>,
 *     checked_at: string
 * }
 */
final class WorkerHealthProbe
{
    private const CACHE_KEY = 'fulfillment-automation:worker-health.v2';

    /**
     * @return WorkerHealthResult
     */
    public function check(): array
    {
        $seconds = max(1, (int) config('fulfillment_automation.progress.worker_health_cache_seconds', 15));

        return Cache::remember(self::CACHE_KEY, $seconds, fn (): array => $this->probe());
    }

    /**
     * @return WorkerHealthResult
     */
    private function probe(): array
    {
        $checkedAt = now()->toIso8601String();
        $empty = $this->unavailable($checkedAt);
        $workerUrl = rtrim((string) config('fulfillment_automation.worker_url'), '/');

        if ($workerUrl === '') {
            return $empty;
        }

        try {
            $response = Http::timeout(3)->get($workerUrl.'/health');

            if (! $response->successful()) {
                return $empty;
            }

            /** @var array<string, mixed> $body */
            $body = $response->json() ?? [];

            $ready = (bool) ($body['ready'] ?? (($body['status'] ?? '') === 'ok'));
            $browserAvailable = array_key_exists('browser_available', $body)
                ? (bool) $body['browser_available']
                : null;
            $status = 'ready';

            if (! $ready || ($body['status'] ?? '') === 'degraded' || $browserAvailable === false) {
                $status = 'degraded';
            }

            /** @var array<string, string> $driverVersions */
            $driverVersions = [];
            if (isset($body['driver_versions']) && is_array($body['driver_versions'])) {
                foreach ($body['driver_versions'] as $key => $value) {
                    if (is_string($key) && (is_string($value) || is_numeric($value))) {
                        $driverVersions[$key] = (string) $value;
                    }
                }
            }

            return [
                'status' => $status,
                'ready' => $status === 'ready',
                'build' => isset($body['build']) ? (string) $body['build'] : null,
                'instance_id' => isset($body['instance_id']) ? (string) $body['instance_id'] : null,
                'uptime_seconds' => isset($body['uptime_seconds']) ? (int) $body['uptime_seconds'] : null,
                'active_count' => isset($body['active_count']) ? (int) $body['active_count'] : null,
                'configured_max_concurrency' => isset($body['configured_max_concurrency'])
                    ? (int) $body['configured_max_concurrency']
                    : null,
                'browser_available' => $browserAvailable,
                'playwright_version' => isset($body['playwright_version']) ? (string) $body['playwright_version'] : null,
                'driver_versions' => $driverVersions,
                'checked_at' => $checkedAt,
            ];
        } catch (Throwable) {
            return $empty;
        }
    }

    /**
     * @return WorkerHealthResult
     */
    private function unavailable(string $checkedAt): array
    {
        return [
            'status' => 'unavailable',
            'ready' => false,
            'build' => null,
            'instance_id' => null,
            'uptime_seconds' => null,
            'active_count' => null,
            'configured_max_concurrency' => null,
            'browser_available' => null,
            'playwright_version' => null,
            'driver_versions' => [],
            'checked_at' => $checkedAt,
        ];
    }
}
