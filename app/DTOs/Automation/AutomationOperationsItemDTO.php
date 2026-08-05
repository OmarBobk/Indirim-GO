<?php

declare(strict_types=1);

namespace App\DTOs\Automation;

/**
 * Safe-to-display operations board row (C1.1). No player IDs or requirements.
 */
final readonly class AutomationOperationsItemDTO
{
    public function __construct(
        public string $kind,
        public ?string $runUuid,
        public int $fulfillmentId,
        public string $fulfillmentReference,
        public ?string $orderNumber,
        public ?string $packageName,
        public ?string $productName,
        public string $supplierKey,
        public string $phase,
        public ?string $runStatus,
        public ?string $step,
        public string $stepLabel,
        public string $presentation,
        public string $liveness,
        public bool $actionRequired,
        public ?string $runStartedAtIso,
        public ?string $stepStartedAtIso,
        public ?string $lastHeartbeatAtIso,
        public ?string $workerBuild,
        public ?string $workerInstanceId,
        public ?string $driverVersion,
        public ?string $supplierOrderId,
        public ?string $nextReconcileAtIso,
        public int $attempt,
        public ?string $detailRunUuid,
    ) {}
}
