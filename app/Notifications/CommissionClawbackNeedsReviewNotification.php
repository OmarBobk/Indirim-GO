<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\CommissionClawback;
use Illuminate\Support\Facades\Route;

final class CommissionClawbackNeedsReviewNotification extends BaseNotification
{
    public static function fromClawback(CommissionClawback $clawback): self
    {
        $url = Route::has('admin.commission-clawbacks.show')
            ? route('admin.commission-clawbacks.show', ['clawback' => $clawback->public_ref])
            : (Route::has('admin.dashboard') ? route('admin.dashboard') : null);

        return new self(
            sourceType: CommissionClawback::class,
            sourceId: (int) $clawback->id,
            titleKey: 'notifications.commission_clawback_needs_review_title',
            messageKey: 'notifications.commission_clawback_needs_review_message',
            messageParams: [
                'clawback_ref' => (string) $clawback->public_ref,
            ],
            url: $url,
        );
    }
}
