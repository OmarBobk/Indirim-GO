<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Str;

class Order extends Model
{
    /** @use HasFactory<\Database\Factories\OrderFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'order_number',
        'currency',
        'subtotal',
        'fee',
        'total',
        'status',
        'paid_at',
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
            'user_id' => 'integer',
            'subtotal' => 'decimal:2',
            'fee' => 'decimal:2',
            'total' => 'decimal:2',
            'status' => OrderStatus::class,
            'paid_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public static function temporaryOrderNumber(): string
    {
        return 'ORD-TMP-'.Str::uuid()->toString();
    }

    public static function generateOrderNumber(int $orderId, ?int $year = null): string
    {
        $year = $year ?? (int) now()->format('Y');

        return sprintf('ORD-%d-%06d', $year, $orderId);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function fulfillments(): HasMany
    {
        return $this->hasMany(Fulfillment::class);
    }

    /**
     * @return HasOne<Commission, $this>
     */
    public function commission(): HasOne
    {
        return $this->hasOne(Commission::class);
    }
}
