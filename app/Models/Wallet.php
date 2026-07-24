<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CreditFacilityStatus;
use App\Enums\WalletType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    /** @use HasFactory<\Database\Factories\WalletFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'type',
        'balance',
        'currency',
        'credit_enabled',
        'credit_limit',
        'payment_terms_days',
        'credit_status',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, mixed>
     */
    protected function casts(): array
    {
        return [
            'user_id' => 'integer',
            'type' => WalletType::class,
            'balance' => 'decimal:2',
            'currency' => 'string',
            'credit_enabled' => 'boolean',
            'credit_limit' => 'decimal:2',
            'payment_terms_days' => 'integer',
            'credit_status' => CreditFacilityStatus::class,
        ];
    }

    public static function forUser(User $user): self
    {
        return self::query()->firstOrCreate(
            ['user_id' => $user->id, 'type' => WalletType::Customer],
            [
                'type' => WalletType::Customer,
                'balance' => 0,
                'currency' => config('billing.currency', 'USD'),
                'credit_enabled' => false,
                'credit_limit' => 0,
                'payment_terms_days' => null,
                'credit_status' => null,
            ]
        );
    }

    public static function forPlatform(): self
    {
        $wallet = self::query()->where('type', WalletType::Platform->value)->first();

        if ($wallet === null) {
            throw new \RuntimeException('Platform wallet does not exist. Run migrations.');
        }

        return $wallet;
    }

    /**
     * Effective overdraft ceiling in ledger currency.
     * Zero when non-customer, credit disabled, status is null, or Suspended.
     * Enabled grants the facility; Active status is required to use it.
     */
    public function effectiveCreditLimit(): string
    {
        if (
            $this->type !== WalletType::Customer
            || ! $this->credit_enabled
            || $this->credit_status !== CreditFacilityStatus::Active
        ) {
            return '0.00';
        }

        $limit = bcadd((string) $this->credit_limit, '0', 2);

        if (bccomp($limit, '0', 2) === -1) {
            return '0.00';
        }

        return $limit;
    }

    /**
     * Minimum balance allowed after a debit (negative of effective credit limit).
     */
    public function minimumAllowedBalance(): string
    {
        $limit = $this->effectiveCreditLimit();

        if (bccomp($limit, '0', 2) === 0) {
            return '0.00';
        }

        return bcmul($limit, '-1', 2);
    }

    /**
     * Maximum amount that can still be spent (balance + effective credit limit, floored at 0).
     */
    public function availableToSpend(): string
    {
        $available = bcadd((string) $this->balance, $this->effectiveCreditLimit(), 2);

        if (bccomp($available, '0', 2) === -1) {
            return '0.00';
        }

        return $available;
    }

    /**
     * Remaining unused credit facility headroom (same as availableToSpend when overdrawn semantics apply).
     * With balance -80 and limit 100 → 20.
     */
    public function availableCredit(): string
    {
        return $this->availableToSpend();
    }

    /**
     * Absolute outstanding overdraft (0 when balance is non-negative).
     */
    public function outstandingDebt(): string
    {
        if (bccomp((string) $this->balance, '0', 2) !== -1) {
            return '0.00';
        }

        return bcmul((string) $this->balance, '-1', 2);
    }

    public function isOverdrawn(): bool
    {
        return bccomp((string) $this->balance, '0', 2) === -1;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(WalletTransaction::class);
    }
}
