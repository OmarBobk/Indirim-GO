<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\PayoutRequest;
use App\Models\User;
use Illuminate\Support\Facades\Route;

final class SalespersonPayoutRequestedNotification extends BaseNotification
{
    public static function forPayoutRequest(PayoutRequest $request, User $salesperson): self
    {
        $currency = (string) $request->currency;
        $amount = (float) $request->eligible_amount;
        $formatted = number_format($amount, 2, '.', '');
        $amountDisplay = strtoupper($currency) === 'USD'
            ? config('billing.currency_symbol', '$').$formatted
            : $formatted.' '.$currency;

        return new self(
            sourceType: PayoutRequest::class,
            sourceId: (int) $request->id,
            titleKey: 'notifications.salesperson_payout_requested_title',
            messageKey: 'notifications.salesperson_payout_requested_message',
            messageParams: [
                'name' => (string) $salesperson->name,
                'eligible_display' => $amountDisplay,
                'id' => (string) $request->id,
            ],
            url: Route::has('admin.payout-requests') ? route('admin.payout-requests') : null
        );
    }
}
