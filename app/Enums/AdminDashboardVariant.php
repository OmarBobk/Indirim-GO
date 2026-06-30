<?php

declare(strict_types=1);

namespace App\Enums;

enum AdminDashboardVariant: string
{
    case Full = 'full';
    case Finance = 'finance';
    case Fulfillment = 'fulfillment';
    case Orders = 'orders';
}
