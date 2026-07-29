<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\TopupRequest;
use Illuminate\Support\Facades\Route;

class TopupRejectedNotification extends BaseNotification
{
    public static function fromTopupRequest(TopupRequest $topupRequest, ?string $reason = null): self
    {
        $amount = number_format((float) $topupRequest->amount, 2);
        $currency = $topupRequest->currency ?? 'USD';
        $amountDisplay = strtoupper($currency) === 'USD'
            ? config('billing.currency_symbol', '$').$amount
            : $amount.' '.$currency;

        return new self(
            sourceType: TopupRequest::class,
            sourceId: $topupRequest->id,
            titleKey: 'notifications.topup_rejected_title',
            messageKey: 'notifications.topup_rejected_message',
            messageParams: [
                'amount_display' => $amountDisplay,
                'reason' => $reason ?? 'notifications.no_reason_given',
            ],
            url: filled($topupRequest->public_ref) && Route::has('wallet.topups.show')
                ? route('wallet.topups.show', ['topup' => $topupRequest->public_ref])
                : (Route::has('wallet.topups.index') ? route('wallet.topups.index') : (Route::has('wallet') ? route('wallet') : null))
        );
    }
}
