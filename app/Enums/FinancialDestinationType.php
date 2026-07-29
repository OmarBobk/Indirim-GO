<?php

declare(strict_types=1);

namespace App\Enums;

enum FinancialDestinationType: string
{
    case Wallet = 'wallet';
    case WalletTopup = 'wallet_topup';
    case WalletTopups = 'wallet_topups';
    case WalletTopupDetail = 'wallet_topup_detail';
    case WalletRefunds = 'wallet_refunds';
    case WalletRefundDetail = 'wallet_refund_detail';
    case WalletTransactions = 'wallet_transactions';
    case WalletTransactionsSearch = 'wallet_transactions_search';
    case TopupProof = 'topup_proof';
    case OrderDetail = 'order_detail';
    case Orders = 'orders';
    case Activity = 'activity';
    case Loyalty = 'loyalty';
    case SalespersonDashboard = 'salesperson_dashboard';
    case PurchaseResume = 'purchase_resume';
}
