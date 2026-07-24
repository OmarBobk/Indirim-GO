<?php

declare(strict_types=1);

namespace App\Actions\SupplierPrices;

use App\Enums\SupplierPriceFlagReason;
use App\Models\Fulfillment;
use App\Notifications\WasimPriceReactiveFlagNotification;
use App\Services\NotificationRecipientService;

class NotifyWasimPriceReactiveFlag
{
    public function __construct(
        private NotificationRecipientService $recipients,
    ) {}

    public function handle(Fulfillment $fulfillment, SupplierPriceFlagReason $reason): void
    {
        if (! config('fulfillment_automation.price_scan.notify_on_reactive_flag', true)) {
            return;
        }

        $fulfillment->loadMissing('orderItem.product');

        $product = $fulfillment->orderItem?->product;

        if ($product === null || ! $product->supplier_price_flag_reason) {
            return;
        }

        $meta = $fulfillment->meta ?? [];
        $automationMeta = is_array($meta['automation'] ?? null) ? $meta['automation'] : [];
        $notifyKey = 'reactive_price_notified_'.$reason->value;

        if (isset($automationMeta[$notifyKey])) {
            return;
        }

        $notification = WasimPriceReactiveFlagNotification::fromFulfillment(
            $fulfillment,
            $product,
            $reason,
        );

        $this->recipients->priceReviewRecipients()->each(
            fn ($user) => $user->notify($notification)
        );

        $automationMeta[$notifyKey] = now()->toIso8601String();
        $meta['automation'] = $automationMeta;

        $fulfillment->update(['meta' => $meta]);
    }
}
