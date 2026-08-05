<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\AutomationSupplierCircuit;
use Illuminate\Support\Facades\Route;

class AutomationCircuitPausedNotification extends BaseNotification
{
    public static function fromCircuit(AutomationSupplierCircuit $circuit): self
    {
        return new self(
            sourceType: AutomationSupplierCircuit::class,
            sourceId: (int) $circuit->id,
            title: __('notifications.automation_circuit_paused_title'),
            message: __('notifications.automation_circuit_paused_message', [
                'capability' => __('messages.automation_circuit_capability_'.$circuit->capability->value),
                'reason' => (string) ($circuit->reason_code ?? ''),
            ]),
            url: Route::has('admin.automation.index') ? route('admin.automation.index') : null,
            traceId: 'automation-circuit-paused-'.$circuit->supplier_key.'-'.$circuit->capability->value.'-'.$circuit->version,
            titleKey: 'notifications.automation_circuit_paused_title',
            messageKey: 'notifications.automation_circuit_paused_message',
            messageParams: [
                'capability' => __('messages.automation_circuit_capability_'.$circuit->capability->value),
                'reason' => (string) ($circuit->reason_code ?? ''),
            ],
        );
    }
}
