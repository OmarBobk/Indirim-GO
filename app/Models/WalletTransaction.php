<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Exceptions\PostedWalletTransactionImmutableException;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
    /** @use HasFactory<\Database\Factories\WalletTransactionFactory> */
    use HasFactory;

    public const STATUS_PENDING = 'pending';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REJECTED = 'rejected';

    public const STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_POSTED,
        self::STATUS_REJECTED,
    ];

    /**
     * Posted mechanical fields that cannot change once status is posted.
     *
     * @var list<string>
     */
    public const POSTED_IMMUTABLE_ATTRIBUTES = [
        'wallet_id',
        'type',
        'direction',
        'amount',
        'status',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'public_ref',
        'posted_at',
    ];

    public const UPDATED_AT = null;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'wallet_id',
        'type',
        'direction',
        'amount',
        'status',
        'reference_type',
        'reference_id',
        'idempotency_key',
        'public_ref',
        'posted_at',
        'meta',
    ];

    protected static function booted(): void
    {
        static::updating(function (WalletTransaction $transaction): void {
            if ($transaction->getOriginal('status') !== self::STATUS_POSTED) {
                return;
            }

            foreach (self::POSTED_IMMUTABLE_ATTRIBUTES as $attribute) {
                if ($transaction->isDirty($attribute)) {
                    throw new PostedWalletTransactionImmutableException(
                        "Posted wallet transaction field [{$attribute}] cannot be modified."
                    );
                }
            }
        });

        static::deleting(function (WalletTransaction $transaction): void {
            if ($transaction->status === self::STATUS_POSTED) {
                throw new PostedWalletTransactionImmutableException(
                    'Posted wallet transactions cannot be deleted.'
                );
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'wallet_id' => 'integer',
            'type' => WalletTransactionType::class,
            'direction' => WalletTransactionDirection::class,
            'amount' => 'decimal:2',
            'reference_id' => 'integer',
            'meta' => 'array',
            'posted_at' => 'datetime',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPosted(): bool
    {
        return $this->status === self::STATUS_POSTED;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }
}
