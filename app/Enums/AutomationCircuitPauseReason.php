<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationCircuitPauseReason: string
{
    case SupplierMaintenance = 'supplier_maintenance';
    case WorkerMaintenance = 'worker_maintenance';
    case Investigation = 'investigation';
    case SuspectedUiChange = 'suspected_ui_change';
    case SupplierAccountIssue = 'supplier_account_issue';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function labelKey(): string
    {
        return 'messages.automation_circuit_pause_reason_'.$this->value;
    }

    public function requiresProbeBeforeResume(): bool
    {
        return in_array($this, [
            self::SuspectedUiChange,
            self::SupplierMaintenance,
            self::SupplierAccountIssue,
        ], true);
    }
}
