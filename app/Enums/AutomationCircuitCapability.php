<?php

declare(strict_types=1);

namespace App\Enums;

enum AutomationCircuitCapability: string
{
    case Purchase = 'purchase';
    case Reconcile = 'reconcile';
    case PriceScan = 'price_scan';

    public function labelKey(): string
    {
        return 'messages.automation_circuit_capability_'.$this->value;
    }
}
