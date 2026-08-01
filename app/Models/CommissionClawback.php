<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionClawbackStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommissionClawback extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_ref',
        'commission_id',
        'salesperson_id',
        'fulfillment_id',
        'refund_wallet_transaction_id',
        'original_commission_credit_transaction_id',
        'reversal_wallet_transaction_id',
        'amount',
        'currency',
        'status',
        'policy_version',
        'idempotency_key',
        'failure_code',
        'failure_message_safe',
        'attempted_at',
        'posted_at',
        'needs_review_at',
        'last_retry_at',
        'retry_count',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_ref';
    }

    public function resolveRouteBinding($value, $field = null): ?static
    {
        $field ??= $this->getRouteKeyName();
        $normalized = is_string($value) ? \App\Support\CommissionClawbackPublicRef::normalize($value) : '';

        if ($field === 'public_ref' && ! \App\Support\CommissionClawbackPublicRef::isValidFormat($normalized)) {
            return null;
        }

        return $this->where($field, $field === 'public_ref' ? $normalized : $value)->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_id' => 'integer',
            'salesperson_id' => 'integer',
            'fulfillment_id' => 'integer',
            'refund_wallet_transaction_id' => 'integer',
            'original_commission_credit_transaction_id' => 'integer',
            'reversal_wallet_transaction_id' => 'integer',
            'amount' => 'decimal:2',
            'status' => CommissionClawbackStatus::class,
            'policy_version' => 'integer',
            'attempted_at' => 'datetime',
            'posted_at' => 'datetime',
            'needs_review_at' => 'datetime',
            'last_retry_at' => 'datetime',
            'retry_count' => 'integer',
        ];
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    public function salesperson(): BelongsTo
    {
        return $this->belongsTo(User::class, 'salesperson_id');
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(Fulfillment::class);
    }

    public function refundWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'refund_wallet_transaction_id');
    }

    public function originalCommissionCreditTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'original_commission_credit_transaction_id');
    }

    public function reversalWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'reversal_wallet_transaction_id');
    }

    public function decisions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(CommissionClawbackDecision::class);
    }
}
