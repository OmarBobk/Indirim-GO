<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FulfillmentAutomationRunStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class FulfillmentAutomationRun extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'uuid',
        'fulfillment_id',
        'supplier_key',
        'status',
        'attempt',
        'idempotency_key',
        'external_order_id',
        'error_code',
        'error_message',
        'result_payload',
        'log_excerpt',
        'dispatched_at',
        'started_at',
        'finished_at',
        'callback_received_at',
        'meta',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fulfillment_id' => 'integer',
            'status' => FulfillmentAutomationRunStatus::class,
            'attempt' => 'integer',
            'result_payload' => 'array',
            'log_excerpt' => 'array',
            'dispatched_at' => 'datetime',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'callback_received_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $run): void {
            if ($run->uuid === null || $run->uuid === '') {
                $run->uuid = (string) Str::uuid();
            }
        });
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(Fulfillment::class);
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', FulfillmentAutomationRunStatus::activeValues());
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeTerminal(Builder $query): Builder
    {
        return $query->whereIn('status', FulfillmentAutomationRunStatus::terminalValues());
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    /**
     * @return list<string>
     */
    public function artifactPaths(): array
    {
        $paths = data_get($this->meta, 'artifact_paths', []);

        return is_array($paths) ? array_values(array_filter($paths, fn (mixed $path): bool => is_string($path))) : [];
    }
}
