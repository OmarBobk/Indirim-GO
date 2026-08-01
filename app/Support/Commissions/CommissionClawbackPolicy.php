<?php

declare(strict_types=1);

namespace App\Support\Commissions;

use Illuminate\Support\Carbon;

/**
 * Prospective M7.1 clawback policy gate (config-backed).
 */
final class CommissionClawbackPolicy
{
    public static function policyVersion(): int
    {
        return max(1, (int) config('billing.commission_clawback.policy_version', 1));
    }

    public static function isEffective(?Carbon $at = null): bool
    {
        $at ??= now();
        $raw = config('billing.commission_clawback.effective_at');

        if ($raw === null || $raw === '') {
            return true;
        }

        try {
            $effectiveAt = Carbon::parse((string) $raw);
        } catch (\Throwable) {
            return true;
        }

        return $at->greaterThanOrEqualTo($effectiveAt);
    }

    public static function idempotencyKey(int $commissionId, int $refundWalletTransactionId): string
    {
        return 'commission_clawback:'.$commissionId.':refund:'.$refundWalletTransactionId;
    }

    public static function reversalIdempotencyKey(int $commissionId, int $refundWalletTransactionId): string
    {
        return 'commission_reversal:'.$commissionId.':refund:'.$refundWalletTransactionId;
    }
}
