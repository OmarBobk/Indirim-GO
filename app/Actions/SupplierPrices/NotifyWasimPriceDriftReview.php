<?php

declare(strict_types=1);

namespace App\Actions\SupplierPrices;

use App\Enums\SupplierPriceScanStatus;
use App\Models\SupplierPriceScan;
use App\Notifications\WasimPriceDriftReviewNotification;
use App\Services\NotificationRecipientService;
use App\Services\SupplierPriceScanService;

class NotifyWasimPriceDriftReview
{
    public function __construct(
        private SupplierPriceScanService $priceScanService,
        private NotificationRecipientService $recipients,
    ) {}

    public function handle(int $scanId): void
    {
        if (! config('fulfillment_automation.price_scan.notify_on_drift', true)) {
            return;
        }

        $scan = SupplierPriceScan::query()->find($scanId);

        if ($scan === null || $scan->status !== SupplierPriceScanStatus::Completed) {
            return;
        }

        $meta = $scan->meta ?? [];

        if (isset($meta['drift_review_notified_at'])) {
            return;
        }

        $drifted = (int) ($this->priceScanService->monitorStats()['drifted'] ?? 0);

        if ($drifted <= 0) {
            return;
        }

        $notification = WasimPriceDriftReviewNotification::fromScan($scan, $drifted);

        $this->recipients->priceReviewRecipients()->each(
            fn ($user) => $user->notify($notification)
        );

        $scan->update([
            'meta' => array_merge($meta, [
                'drift_review_notified_at' => now()->toIso8601String(),
            ]),
        ]);
    }
}
