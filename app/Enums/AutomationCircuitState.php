<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationCircuitState: string
{
    case Enabled = 'enabled';
    case PausedAuto = 'paused_auto';
    case PausedManual = 'paused_manual';
    case ProbeRequired = 'probe_required';

    public function blocksDispatch(): bool
    {
        return $this !== self::Enabled;
    }

    public function labelKey(): string
    {
        return 'messages.automation_circuit_state_'.$this->value;
    }

    public function isPaused(): bool
    {
        return in_array($this, [self::PausedAuto, self::PausedManual, self::ProbeRequired], true);
    }
}
