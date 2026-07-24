<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\SupplierPriceScan;
use Illuminate\Support\Facades\Route;

class WasimPriceDriftReviewNotification extends BaseNotification
{
    public static function fromScan(SupplierPriceScan $scan, int $driftedCount): self
    {
        return new self(
            sourceType: SupplierPriceScan::class,
            sourceId: $scan->id,
            title: __('notifications.wasim_price_drift_review_title'),
            message: __('notifications.wasim_price_drift_review_message', [
                'count' => $driftedCount,
            ]),
            url: Route::has('price-drift') ? route('price-drift') : null,
            traceId: 'wasim-price-drift-'.$scan->uuid,
        );
    }
}
