<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MobileTopupAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileTopupAttempt extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'key_hash',
        'request_hash',
        'status',
        'topup_request_id',
        'failure_code',
        'processing_started_at',
        'completed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'topup_request_id' => 'integer',
            'status' => MobileTopupAttemptStatus::class,
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function topupRequest(): BelongsTo
    {
        return $this->belongsTo(TopupRequest::class);
    }
}
