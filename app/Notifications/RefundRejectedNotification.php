<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Route;

class RefundRejectedNotification extends BaseNotification
{
    public static function fromRefundTransaction(WalletTransaction $transaction): self
    {
        $amountDisplay = config('billing.currency_symbol', '$').number_format((float) $transaction->amount, 2);
        $orderNumber = data_get($transaction->meta, 'order_number', '');

        return new self(
            sourceType: WalletTransaction::class,
            sourceId: $transaction->id,
            titleKey: 'notifications.refund_rejected_title',
            messageKey: 'notifications.refund_rejected_message',
            messageParams: [
                'amount_display' => $amountDisplay,
                'order_number' => $orderNumber,
            ],
            url: filled($transaction->public_ref) && Route::has('wallet.refunds.show')
                ? route('wallet.refunds.show', ['refund' => $transaction->public_ref])
                : (Route::has('wallet.refunds.index')
                    ? route('wallet.refunds.index')
                    : (Route::has('orders.index') ? route('orders.index') : null))
        );
    }
}
