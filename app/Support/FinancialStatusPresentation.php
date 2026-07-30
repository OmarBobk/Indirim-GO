<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\FinancialPendingActor;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;

/**
 * Small M6 financial status vocabulary for overview surfaces.
 */
final class FinancialStatusPresentation
{
    public static function pendingActorLabel(FinancialPendingActor $actor): string
    {
        return match ($actor) {
            FinancialPendingActor::WaitingStaff => __('messages.financial_status_waiting_review'),
            FinancialPendingActor::NeedsCustomer => __('messages.financial_status_needs_action'),
            FinancialPendingActor::Informational => __('messages.financial_status_completed_money'),
        };
    }

    public static function pendingActorBadgeColor(FinancialPendingActor $actor): string
    {
        return match ($actor) {
            FinancialPendingActor::WaitingStaff => 'amber',
            FinancialPendingActor::NeedsCustomer => 'red',
            FinancialPendingActor::Informational => 'green',
        };
    }

    public static function transactionStatusLabel(string $status, WalletTransactionType $type, WalletTransactionDirection $direction): string
    {
        if ($type === WalletTransactionType::Refund && $direction === WalletTransactionDirection::Credit) {
            return __('messages.financial_status_refunded');
        }

        if ($direction === WalletTransactionDirection::Credit) {
            return __('messages.financial_status_credited');
        }

        return __('messages.financial_status_debited');
    }

    public static function directionLabel(WalletTransactionDirection $direction): string
    {
        return $direction === WalletTransactionDirection::Credit
            ? __('messages.financial_direction_credited')
            : __('messages.financial_direction_debited');
    }
}
