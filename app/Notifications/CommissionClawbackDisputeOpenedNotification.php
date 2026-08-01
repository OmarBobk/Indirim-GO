<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CommissionClawback;
use App\Models\CommissionClawbackDecision;
use Illuminate\Support\Facades\Route;

final class CommissionClawbackDisputeOpenedNotification extends BaseNotification
{
    public static function fromDecision(
        CommissionClawback $clawback,
        CommissionClawbackDecision $decision,
    ): self {
        $url = Route::has('wallet.earnings.index')
            ? route('wallet.earnings.index')
            : null;

        return new self(
            sourceType: CommissionClawbackDecision::class,
            sourceId: (int) $decision->id,
            titleKey: 'notifications.commission_clawback_dispute_opened_title',
            messageKey: 'notifications.commission_clawback_dispute_opened_message',
            messageParams: [
                'clawback_ref' => (string) $clawback->public_ref,
            ],
            url: $url,
        );
    }
}
