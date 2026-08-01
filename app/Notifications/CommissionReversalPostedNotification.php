<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CommissionClawback;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;
use Illuminate\Support\Facades\Route;

final class CommissionReversalPostedNotification extends BaseNotification
{
    public static function fromClawback(CommissionClawback $clawback, ?WalletTransaction $reversal = null): self
    {
        $amount = LedgerMoney::normalize((string) $clawback->amount);
        $formatted = number_format((float) $amount, 2, '.', '');
        $currency = strtoupper((string) ($clawback->currency ?: 'USD'));
        $amountDisplay = $currency === 'USD'
            ? config('billing.currency_symbol', '$').$formatted
            : $formatted.' '.$currency;

        $url = null;
        if ($reversal !== null && is_string($reversal->public_ref) && Route::has('wallet.transactions.show')) {
            $url = route('wallet.transactions.show', ['publicRef' => $reversal->public_ref]);
        } elseif (Route::has('wallet.earnings.index')) {
            $url = route('wallet.earnings.index');
        }

        return new self(
            sourceType: CommissionClawback::class,
            sourceId: (int) $clawback->id,
            titleKey: 'notifications.commission_reversal_posted_title',
            messageKey: 'notifications.commission_reversal_posted_message',
            messageParams: [
                'amount_display' => $amountDisplay,
                'clawback_ref' => (string) $clawback->public_ref,
            ],
            url: $url,
        );
    }
}
