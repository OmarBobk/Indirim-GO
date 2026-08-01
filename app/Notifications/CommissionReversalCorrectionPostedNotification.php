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

final class CommissionReversalCorrectionPostedNotification extends BaseNotification
{
    public static function fromDecision(
        CommissionClawback $clawback,
        CommissionClawbackDecision $decision,
        WalletTransaction $credit,
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
            ? 'notifications.commission_reversal_correction_debt_message'
            : 'notifications.commission_reversal_correction_cleared_message';

        $url = Route::has('wallet.transactions.show')
            ? route('wallet.transactions.show', ['publicRef' => $credit->public_ref])
            : (Route::has('wallet.earnings.index') ? route('wallet.earnings.index') : null);

        return new self(
            sourceType: CommissionClawbackDecision::class,
            sourceId: (int) $decision->id,
            titleKey: 'notifications.commission_reversal_correction_title',
            messageKey: $messageKey,
            messageParams: [
                'amount_display' => $amountDisplay,
                'clawback_ref' => (string) $clawback->public_ref,
            ],
            url: $url,
        );
    }
}
