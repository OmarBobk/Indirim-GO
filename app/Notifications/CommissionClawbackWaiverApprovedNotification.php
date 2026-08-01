<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\Commissions\SalespersonClawbackDebt;
use App\Support\LedgerMoney;
use Illuminate\Support\Facades\Route;

final class CommissionClawbackWaiverApprovedNotification extends BaseNotification
{
    public static function fromDecision(
        CommissionClawback $clawback,
        CommissionClawbackDecision $decision,
        ?WalletTransaction $credit = null,
    ): self {
        $amount = LedgerMoney::normalize((string) ($decision->amount ?? '0'));
        $currency = strtoupper((string) ($clawback->currency ?: 'USD'));
        $amountDisplay = $currency === 'USD'
            ? config('billing.currency_symbol', '$').$amount
            : $amount.' '.$currency;

        $salesperson = User::query()->find((int) $clawback->salesperson_id);
        $wallet = $salesperson !== null ? Wallet::forUser($salesperson) : null;
        $hasDebt = $wallet !== null && app(SalespersonClawbackDebt::class)->hasOutstandingDebt($wallet);

        $messageKey = $hasDebt
            ? 'notifications.commission_clawback_waiver_approved_debt_message'
            : 'notifications.commission_clawback_waiver_approved_cleared_message';

        $url = null;
        if ($credit !== null && is_string($credit->public_ref) && Route::has('wallet.transactions.show')) {
            $url = route('wallet.transactions.show', ['publicRef' => $credit->public_ref]);
        } elseif (Route::has('wallet.earnings.index')) {
            $url = route('wallet.earnings.index');
        }

        return new self(
            sourceType: CommissionClawbackDecision::class,
            sourceId: (int) $decision->id,
            titleKey: 'notifications.commission_clawback_waiver_approved_title',
            messageKey: $messageKey,
            messageParams: [
                'amount_display' => $amountDisplay,
                'clawback_ref' => (string) $clawback->public_ref,
            ],
            url: $url,
        );
    }
}
