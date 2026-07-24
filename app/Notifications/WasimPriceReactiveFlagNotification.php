<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\SupplierPriceFlagReason;
use App\Models\Fulfillment;
use App\Models\Product;
use Illuminate\Support\Facades\Route;

class WasimPriceReactiveFlagNotification extends BaseNotification
{
    public static function fromFulfillment(
        Fulfillment $fulfillment,
        Product $product,
        SupplierPriceFlagReason $reason,
    ): self {
        $messageKey = $reason === SupplierPriceFlagReason::MarginInsufficient
            ? 'notifications.wasim_price_reactive_flag_margin_message'
            : 'notifications.wasim_price_reactive_flag_mismatch_message';

        return new self(
            sourceType: Fulfillment::class,
            sourceId: $fulfillment->id,
            title: __('notifications.wasim_price_reactive_flag_title'),
            message: __($messageKey, [
                'product' => $product->name,
                'product_id' => $product->id,
            ]),
            url: Route::has('price-drift') ? route('price-drift') : null,
            traceId: 'wasim-reactive-flag-'.$fulfillment->id.'-'.$reason->value,
        );
    }
}
