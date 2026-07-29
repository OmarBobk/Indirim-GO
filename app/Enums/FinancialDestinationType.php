<?php

declare(strict_types=1);

namespace App\Enums;

enum FinancialDestinationType: string
{
    case Wallet = 'wallet';
    case WalletTopup = 'wallet_topup';
    case OrderDetail = 'order_detail';
    case Orders = 'orders';
    case Activity = 'activity';
    case Loyalty = 'loyalty';
    case SalespersonDashboard = 'salesperson_dashboard';
    case PurchaseResume = 'purchase_resume';
}
