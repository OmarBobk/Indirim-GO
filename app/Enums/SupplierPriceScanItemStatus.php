<?php

declare(strict_types=1);

namespace App\Enums;

enum SupplierPriceScanItemStatus: string
{
    case Pending = 'pending';
    case Ok = 'ok';
    case Failed = 'failed';
}
