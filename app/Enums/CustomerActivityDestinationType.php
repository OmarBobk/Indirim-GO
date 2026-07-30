<?php

declare(strict_types=1);

namespace App\Enums;

enum CustomerActivityDestinationType: string
{
    case OrderDetail = 'order_detail';
    case Orders = 'orders';
    case Wallet = 'wallet';
    case WalletTopup = 'wallet_topup';
    case WalletRefund = 'wallet_refund';
    case Cart = 'cart';
    case Loyalty = 'loyalty';
    case Referral = 'referral';
    case Account = 'account';
    case Profile = 'profile';
    case Activity = 'activity';
}
