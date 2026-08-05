<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AutomationSupplierCircuit;
use Illuminate\Support\Facades\Route;

class AutomationCircuitProbeRequiredNotification extends BaseNotification
{
    public static function fromCircuit(AutomationSupplierCircuit $circuit): self
    {
        return new self(
            sourceType: AutomationSupplierCircuit::class,
            sourceId: (int) $circuit->id,
            title: __('notifications.automation_circuit_probe_required_title'),
            message: __('notifications.automation_circuit_probe_required_message', [
                'capability' => __('messages.automation_circuit_capability_'.$circuit->capability->value),
            ]),
            url: Route::has('admin.automation.index') ? route('admin.automation.index') : null,
            traceId: 'automation-circuit-probe-'.$circuit->supplier_key.'-'.$circuit->capability->value.'-'.$circuit->version,
            titleKey: 'notifications.automation_circuit_probe_required_title',
            messageKey: 'notifications.automation_circuit_probe_required_message',
            messageParams: [
                'capability' => __('messages.automation_circuit_capability_'.$circuit->capability->value),
            ],
        );
    }
}
