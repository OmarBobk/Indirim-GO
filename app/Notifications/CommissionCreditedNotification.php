<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Commission;
use Illuminate\Support\Facades\Route;

final class CommissionCreditedNotification extends BaseNotification
{
    public static function fromCredited(int $commissionId, float $amount, string $currency): self
    {
        $formatted = number_format($amount, 2, '.', '');
        $amountDisplay = strtoupper($currency) === 'USD'
            ? config('billing.currency_symbol', '$').$formatted
            : $formatted.' '.$currency;

        return new self(
            sourceType: Commission::class,
            sourceId: $commissionId,
            titleKey: 'notifications.commission_credited_title',
            messageKey: 'notifications.commission_credited_message',
            messageParams: [
                'amount_display' => $amountDisplay,
            ],
            url: Route::has('wallet') ? route('wallet') : null
        );
    }
}
