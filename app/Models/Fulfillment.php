<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FulfillmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fulfillments track per-item delivery state after payment.
 */
class Fulfillment extends Model
{
    /** @use HasFactory<\Database\Factories\FulfillmentFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'order_id',
        'order_item_id',
        'claimed_by',
        'provider',
        'status',
        'attempts',
        'last_error',
        'processed_at',
        'completed_at',
        'claimed_at',
        'meta',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'order_id' => 'integer',
            'order_item_id' => 'integer',
            'claimed_by' => 'integer',
            'status' => FulfillmentStatus::class,
            'attempts' => 'integer',
            'processed_at' => 'datetime',
            'completed_at' => 'datetime',
            'claimed_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    public function claimer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'claimed_by');
    }

    public function logs(): HasMany
    {
        return $this->hasMany(FulfillmentLog::class);
    }

    public function automationRuns(): HasMany
    {
        return $this->hasMany(FulfillmentAutomationRun::class);
    }

    public function isBrowserAutomated(): bool
    {
        return str_starts_with($this->provider, 'browser:');
    }

    public function browserSupplierKey(): ?string
    {
        if (! $this->isBrowserAutomated()) {
            return null;
        }

        $key = substr($this->provider, strlen('browser:'));

        return $key !== '' ? $key : null;
    }

    public function settlements(): BelongsToMany
    {
        return $this->belongsToMany(Settlement::class, 'settlement_fulfillments')
            ->withTimestamps();
    }
}
