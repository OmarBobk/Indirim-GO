<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MobileCheckoutAttemptStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MobileCheckoutAttempt extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'key_hash',
        'request_hash',
        'status',
        'order_id',
        'receipt',
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
            'order_id' => 'integer',
            'status' => MobileCheckoutAttemptStatus::class,
            'receipt' => 'array',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
