<?php

declare(strict_types=1);

namespace App\DTOs\Automation;

use Illuminate\Support\Carbon;

/**
 * Validated inbound progress/heartbeat payload from the automation worker (C1.1).
 */
final readonly class AutomationProgressPayloadDTO
{
    /**
     * @param  array<string, scalar>|null  $safeParams
     */
    public function __construct(
        public int $progressSequence,
        public string $phase,
        public string $step,
        public Carbon $emittedAt,
        public bool $heartbeat,
        public ?string $safeMessageCode,
        public ?array $safeParams,
        public string $workerInstanceId,
        public string $workerBuild,
        public string $driverName,
        public string $driverVersion,
        public ?string $detectedUiVersion,
        public ?string $pageContractVersion,
        public ?string $sessionAlias,
    ) {}

    /**
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $safeParams = null;
        if (isset($validated['safe_params']) && is_array($validated['safe_params'])) {
            $safeParams = [];
            foreach ($validated['safe_params'] as $key => $value) {
                if (is_string($key) && (is_string($value) || is_int($value) || is_float($value) || is_bool($value))) {
                    $safeParams[$key] = $value;
                }
            }
        }

        $sequence = $validated['progress_sequence'] ?? $validated['sequence'] ?? 0;

        return new self(
            progressSequence: (int) $sequence,
            phase: (string) $validated['phase'],
            step: (string) $validated['step'],
            emittedAt: Carbon::parse((string) $validated['emitted_at']),
            heartbeat: (bool) ($validated['heartbeat'] ?? false),
            safeMessageCode: isset($validated['safe_message_code']) ? (string) $validated['safe_message_code'] : null,
            safeParams: $safeParams,
            workerInstanceId: (string) ($validated['worker_instance_id'] ?? ''),
            workerBuild: (string) ($validated['worker_build'] ?? ''),
            driverName: (string) ($validated['driver_name'] ?? ''),
            driverVersion: (string) ($validated['driver_version'] ?? ''),
            detectedUiVersion: isset($validated['detected_ui_version']) ? (string) $validated['detected_ui_version'] : null,
            pageContractVersion: isset($validated['page_contract_version']) ? (string) $validated['page_contract_version'] : null,
            sessionAlias: isset($validated['session_alias']) ? (string) $validated['session_alias'] : null,
        );
    }
}
