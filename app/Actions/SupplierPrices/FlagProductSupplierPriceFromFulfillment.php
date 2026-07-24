<?php

declare(strict_types=1);

namespace App\Actions\SupplierPrices;

use App\Enums\SupplierPriceFlagReason;
use App\Models\Fulfillment;
use App\Services\SupplierPriceScanService;

class FlagProductSupplierPriceFromFulfillment
{
    public function __construct(
        private readonly SupplierPriceScanService $priceScanService,
        private readonly NotifyWasimPriceReactiveFlag $notifyReactiveFlag,
    ) {}

    /**
     * @param  array<string, mixed>  $deliveredPayload
     */
    public function handleMarginInsufficient(Fulfillment $fulfillment, array $deliveredPayload): void
    {
        if (! config('fulfillment_automation.price_scan.reactive_flags_enabled', true)) {
            return;
        }

        $supplierTotal = $deliveredPayload['supplier_total'] ?? null;

        if (! is_numeric($supplierTotal)) {
            return;
        }

        $quantity = $this->resolveQuantity($fulfillment, $deliveredPayload);
        $reason = SupplierPriceFlagReason::MarginInsufficient;

        if (! $this->priceScanService->flagProductFromFulfillmentObservation(
            $fulfillment,
            (float) $supplierTotal,
            $quantity,
            $reason,
        )) {
            return;
        }

        $this->notifyReactiveFlag->handle($fulfillment->refresh(), $reason);
    }

    /**
     * @param  array<string, mixed>  $deliveredPayload
     */
    public function handleSupplierEntryPrice(Fulfillment $fulfillment, array $deliveredPayload): void
    {
        if (! config('fulfillment_automation.price_scan.reactive_flags_enabled', true)) {
            return;
        }

        $supplierEntryPrice = $deliveredPayload['supplier_entry_price'] ?? null;

        if (! is_numeric($supplierEntryPrice)) {
            return;
        }

        $quantity = $this->resolveQuantity($fulfillment, $deliveredPayload);
        $reason = SupplierPriceFlagReason::FulfillmentMismatch;

        if (! $this->priceScanService->flagProductFromFulfillmentObservation(
            $fulfillment,
            (float) $supplierEntryPrice,
            $quantity,
            $reason,
        )) {
            return;
        }

        $this->notifyReactiveFlag->handle($fulfillment->refresh(), $reason);
    }

    /**
     * @param  array<string, mixed>  $deliveredPayload
     */
    private function resolveQuantity(Fulfillment $fulfillment, array $deliveredPayload): ?int
    {
        $fromPayload = $deliveredPayload['custom_quantity'] ?? null;

        if (is_numeric($fromPayload) && (int) $fromPayload > 0) {
            return (int) $fromPayload;
        }

        $fromMeta = data_get($fulfillment->meta, 'amount');

        if (is_numeric($fromMeta) && (int) $fromMeta > 0) {
            return (int) $fromMeta;
        }

        return null;
    }
}
