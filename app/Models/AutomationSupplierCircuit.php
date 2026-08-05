<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AutomationCircuitCapability;
use App\Enums\AutomationCircuitOpenedSource;
use App\Enums\AutomationCircuitState;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AutomationSupplierCircuit extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'supplier_key',
        'capability',
        'state',
        'reason_code',
        'safe_reason_context',
        'opened_at',
        'opened_by',
        'opened_source',
        'last_failure_at',
        'consecutive_failure_count',
        'failure_window_started_at',
        'recent_signal_keys',
        'last_probe_at',
        'last_probe_state',
        'last_healthy_at',
        'resumed_at',
        'resumed_by',
        'resume_source',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'capability' => AutomationCircuitCapability::class,
            'state' => AutomationCircuitState::class,
            'opened_source' => AutomationCircuitOpenedSource::class,
            'opened_at' => 'datetime',
            'last_failure_at' => 'datetime',
            'failure_window_started_at' => 'datetime',
            'recent_signal_keys' => 'array',
            'last_probe_at' => 'datetime',
            'last_healthy_at' => 'datetime',
            'resumed_at' => 'datetime',
            'consecutive_failure_count' => 'integer',
            'version' => 'integer',
            'opened_by' => 'integer',
            'resumed_by' => 'integer',
        ];
    }

    public function openedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function resumedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resumed_by');
    }

    public function blocksDispatch(): bool
    {
        return $this->state->blocksDispatch();
    }

    public function bumpVersion(): void
    {
        $this->version = ((int) $this->version) + 1;
    }
}
