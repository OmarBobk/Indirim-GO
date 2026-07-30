<?php

declare(strict_types=1);

namespace App\Support\Financial;

use App\Enums\CustomerFinancialInvalidationReason;

final class CustomerFinancialRealtimeScope
{
    /**
     * Surface identifiers are server-owned; browser payloads never select data ownership.
     */
    public const string SURFACE_OVERVIEW = 'overview';

    public const string SURFACE_LEDGER = 'ledger';

    public const string SURFACE_TRANSACTION_DETAIL = 'transaction_detail';

    public const string SURFACE_TOPUP_LIST = 'topup_list';

    public const string SURFACE_TOPUP_DETAIL = 'topup_detail';

    public const string SURFACE_REFUND_LIST = 'refund_list';

    public const string SURFACE_REFUND_DETAIL = 'refund_detail';

    public const string SURFACE_EARNINGS = 'earnings';

    /**
     * @param  array<string, mixed>  $payload
     * @return list<CustomerFinancialInvalidationReason>
     */
    public static function reasons(array $payload): array
    {
        $rawReasons = $payload['reasons'] ?? [];

        if (! is_array($rawReasons)) {
            return [];
        }

        $reasons = [];

        foreach ($rawReasons as $rawReason) {
            if (! is_string($rawReason)) {
                continue;
            }

            $reason = CustomerFinancialInvalidationReason::tryFrom($rawReason);

            if ($reason !== null) {
                $reasons[$reason->value] = $reason;
            }
        }

        return array_values($reasons);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function isRelevant(array $payload, string $surface): bool
    {
        if (($payload['isReconcile'] ?? false) === true) {
            return in_array($surface, self::surfaces(), true);
        }

        foreach (self::reasons($payload) as $reason) {
            if (in_array($surface, self::surfacesFor($reason), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    private static function surfaces(): array
    {
        return [
            self::SURFACE_OVERVIEW,
            self::SURFACE_LEDGER,
            self::SURFACE_TRANSACTION_DETAIL,
            self::SURFACE_TOPUP_LIST,
            self::SURFACE_TOPUP_DETAIL,
            self::SURFACE_REFUND_LIST,
            self::SURFACE_REFUND_DETAIL,
            self::SURFACE_EARNINGS,
        ];
    }

    /**
     * @return list<string>
     */
    private static function surfacesFor(CustomerFinancialInvalidationReason $reason): array
    {
        return match ($reason) {
            CustomerFinancialInvalidationReason::TransactionPosted => [
                self::SURFACE_OVERVIEW,
                self::SURFACE_LEDGER,
            ],
            CustomerFinancialInvalidationReason::BalanceChanged,
            CustomerFinancialInvalidationReason::CreditFacilityChanged => [
                self::SURFACE_OVERVIEW,
            ],
            CustomerFinancialInvalidationReason::TopupStateChanged => [
                self::SURFACE_OVERVIEW,
                self::SURFACE_TRANSACTION_DETAIL,
                self::SURFACE_TOPUP_LIST,
                self::SURFACE_TOPUP_DETAIL,
            ],
            CustomerFinancialInvalidationReason::RefundStateChanged => [
                self::SURFACE_OVERVIEW,
                self::SURFACE_TRANSACTION_DETAIL,
                self::SURFACE_REFUND_LIST,
                self::SURFACE_REFUND_DETAIL,
            ],
            CustomerFinancialInvalidationReason::CommissionStateChanged => [
                self::SURFACE_TRANSACTION_DETAIL,
                self::SURFACE_EARNINGS,
            ],
            CustomerFinancialInvalidationReason::PayoutRequestStateChanged => [
                self::SURFACE_EARNINGS,
            ],
        };
    }
}
