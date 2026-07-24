<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierPriceFlagReason: string
{
    case MarginInsufficient = 'margin_insufficient';
    case FulfillmentMismatch = 'fulfillment_mismatch';
}
