<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\HistoricalCommissionExposureOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HistoricalCommissionExposureReview extends Model
{
    /**
     * @var list<string>
     */
    protected $fillable = [
        'commission_id',
        'refund_wallet_transaction_id',
        'outcome',
        'reason_code',
        'admin_note',
        'reviewed_by',
        'reviewed_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'commission_id' => 'integer',
            'refund_wallet_transaction_id' => 'integer',
            'reviewed_by' => 'integer',
            'outcome' => HistoricalCommissionExposureOutcome::class,
            'reviewed_at' => 'datetime',
        ];
    }

    public function commission(): BelongsTo
    {
        return $this->belongsTo(Commission::class);
    }

    public function refundWalletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'refund_wallet_transaction_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
