<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FulfillmentAutomationRunEvent extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'run_id',
        'sequence',
        'phase',
        'step',
        'safe_message_code',
        'safe_params',
        'occurred_at',
        'worker_instance_id',
        'worker_build',
        'created_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'run_id' => 'integer',
            'sequence' => 'integer',
            'safe_params' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(FulfillmentAutomationRun::class, 'run_id');
    }
}
