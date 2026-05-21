<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\TopupRequestStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;

class TopupRequest extends Model
{
    /** @use HasFactory<\Database\Factories\TopupRequestFactory> */
    use HasFactory;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'wallet_id',
        'payment_method_id',
        'amount',
        'currency',
        'status',
        'note',
        'approved_by',
        'approved_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'wallet_id' => 'integer',
            'payment_method_id' => 'integer',
            'amount' => 'decimal:2',
            'currency' => 'string',
            'status' => TopupRequestStatus::class,
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $topupRequest): void {
            if ($topupRequest->wallet_id !== null) {
                return;
            }

            $user = $topupRequest->user ?? User::query()->find($topupRequest->user_id);

            if ($user === null) {
                return;
            }

            $topupRequest->wallet_id = Wallet::forUser($user)->id;
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }

    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    public function proofs(): HasMany
    {
        return $this->hasMany(TopupProof::class);
    }

    public function walletTransaction(): MorphOne
    {
        return $this->morphOne(WalletTransaction::class, 'reference');
    }
}
