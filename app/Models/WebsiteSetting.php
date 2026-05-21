<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WebsiteSetting extends Model
{
    protected $table = 'website_settings';

    protected $fillable = [
        'contact_email',
        'primary_phone',
        'secondary_phone',
        'prices_visible',
        'usd_try_rate',
        'usd_try_rate_updated_at',
        'commission_payout_wait_days',
        'commission_payout_min_amount',
        'default_commission_rate_percent',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'prices_visible' => 'boolean',
            'usd_try_rate' => 'decimal:6',
            'usd_try_rate_updated_at' => 'datetime',
            'commission_payout_wait_days' => 'integer',
            'commission_payout_min_amount' => 'decimal:2',
            'default_commission_rate_percent' => 'decimal:2',
        ];
    }

    /**
     * Get the singleton instance (first row). Creates one with defaults if none exists.
     */
    public static function instance(): self
    {
        $row = self::query()->first();
        if ($row !== null) {
            return $row;
        }

        return self::query()->create([
            'contact_email' => null,
            'primary_phone' => null,
            'secondary_phone' => null,
            'prices_visible' => true,
            'usd_try_rate' => null,
            'usd_try_rate_updated_at' => null,
            'commission_payout_wait_days' => 3,
            'commission_payout_min_amount' => 200,
            'default_commission_rate_percent' => 20,
        ]);
    }

    public static function getContactEmail(): ?string
    {
        $value = self::instance()->contact_email;

        return $value !== null && $value !== '' ? $value : null;
    }

    public static function getPrimaryPhone(): ?string
    {
        $value = self::instance()->primary_phone;

        return $value !== null && $value !== '' ? $value : null;
    }

    public static function getSecondaryPhone(): ?string
    {
        $value = self::instance()->secondary_phone;

        return $value !== null && $value !== '' ? $value : null;
    }

    public static function getPricesVisible(): bool
    {
        return (bool) self::instance()->prices_visible;
    }

    public static function getUsdTryRate(): ?float
    {
        $rate = self::instance()->usd_try_rate;
        if ($rate === null) {
            return null;
        }

        $floatRate = (float) $rate;

        return $floatRate > 0 ? $floatRate : null;
    }

    /**
     * Fixed six-decimal rate string for admin UI, or null when unset or not positive.
     */
    public static function getUsdTryRateAdminDisplay(): ?string
    {
        $raw = self::instance()->usd_try_rate;
        if ($raw === null) {
            return null;
        }

        $float = (float) $raw;
        if ($float <= 0) {
            return null;
        }

        return number_format($float, 6, '.', '');
    }

    public static function getUsdTryRateUpdatedAt(): ?\DateTimeInterface
    {
        return self::instance()->usd_try_rate_updated_at;
    }

    public static function getCommissionPayoutWaitDays(): int
    {
        $value = (int) self::instance()->commission_payout_wait_days;

        return max(0, min($value, 365));
    }

    public static function getCommissionPayoutMinAmount(): float
    {
        $value = (float) self::instance()->commission_payout_min_amount;

        return max(0, $value);
    }

    /** Default referral commission % when a salesperson has no custom rate (two decimal places). */
    public static function getDefaultCommissionRatePercent(): string
    {
        $raw = self::instance()->default_commission_rate_percent;
        $float = $raw !== null
            ? (float) $raw
            : (float) config('referral.default_commission_rate_percent', '20.00');
        $clamped = max(0.01, min(100.0, $float));

        return number_format($clamped, 2, '.', '');
    }
}
