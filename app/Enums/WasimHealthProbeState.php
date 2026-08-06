<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * States reported by the worker's `POST /v1/suppliers/wasim/probe` endpoint (C1.2).
 */
enum WasimHealthProbeState: string
{
    case Healthy = 'healthy';
    case Degraded = 'degraded';
    case AuthenticationRequired = 'authentication_required';
    case UnsupportedUi = 'unsupported_ui';
    case Maintenance = 'maintenance';
    case Unreachable = 'unreachable';
    case ContractFailed = 'contract_failed';
    case NotConfigured = 'not_configured';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function labelKey(): string
    {
        return 'messages.automation_wasim_probe_state_'.$this->value;
    }
}
