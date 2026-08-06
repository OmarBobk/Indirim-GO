<?php

declare(strict_types=1);

namespace App\DTOs\Automation;

/**
 * Full payload for the admin automation operations dashboard.
 */
final readonly class AutomationOperationsDashboardDTO
{
    /**
     * @param  list<AutomationHealthCardDTO>  $healthCards
     * @param  list<AutomationOperationsItemDTO>  $workingNow
     * @param  list<AutomationOperationsItemDTO>  $waitingSupplier
     * @param  list<AutomationOperationsItemDTO>  $scheduledReconcile
     * @param  list<AutomationOperationsItemDTO>  $reconcileExhausted
     * @param  list<AutomationOperationsItemDTO>  $needsAttention
     * @param  list<AutomationOperationsItemDTO>  $recentOutcomes
     * @param  list<AutomationOperationsItemDTO>  $waitingRecovery
     */
    public function __construct(
        public array $healthCards,
        public array $workingNow,
        public array $waitingSupplier,
        public array $scheduledReconcile,
        public array $reconcileExhausted,
        public array $needsAttention,
        public array $recentOutcomes,
        public array $waitingRecovery = [],
    ) {}
}
