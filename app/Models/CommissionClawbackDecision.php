<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CommissionClawbackDecisionStatus;
use App\Enums\CommissionClawbackDecisionType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommissionClawbackDecision extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_ref',
        'commission_clawback_id',
        'parent_decision_id',
        'type',
        'status',
        'amount',
        'reason_code',
        'admin_note',
        'safe_resolution_summary',
        'actor_id',
        'related_wallet_transaction_id',
        'idempotency_key',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_clawback_id' => 'integer',
            'parent_decision_id' => 'integer',
            'actor_id' => 'integer',
            'related_wallet_transaction_id' => 'integer',
            'amount' => 'decimal:2',
            'type' => CommissionClawbackDecisionType::class,
            'status' => CommissionClawbackDecisionStatus::class,
            'decided_at' => 'datetime',
        ];
    }

    public function clawback(): BelongsTo
    {
        return $this->belongsTo(CommissionClawback::class, 'commission_clawback_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_decision_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_decision_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function relatedWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'related_wallet_transaction_id');
    }
}
