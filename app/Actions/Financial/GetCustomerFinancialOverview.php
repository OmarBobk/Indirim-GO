<?php

declare(strict_types=1);

namespace App\Actions\Financial;

use App\DTOs\Financial\FinancialOverviewDTO;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WebsiteSetting;
use App\Support\Financial\CustomerPendingFinancialReader;
use App\Support\Financial\CustomerRecentWalletTransactionsReader;
use App\Support\Financial\GetCustomerWalletBalanceSummary;
use App\Support\PurchaseResumeIntent;

/**
 * Orchestrates the customer Financial Overview read model (M6.1).
 */
final class GetCustomerFinancialOverview
{
    public function __construct(
        private readonly GetCustomerWalletBalanceSummary $balanceSummary,
        private readonly CustomerPendingFinancialReader $pendingReader,
        private readonly CustomerRecentWalletTransactionsReader $recentReader,
    ) {}

    public function handle(User $user): FinancialOverviewDTO
    {
        $wallet = Wallet::forUser($user);
        $balance = $this->balanceSummary->handle($wallet);
        $pending = $this->pendingReader->handle($user, $wallet);
        $recent = $this->recentReader->handle($user, $wallet);

        return new FinancialOverviewDTO(
            balance: $balance,
            pendingItems: $pending['items'],
            pendingHasMore: $pending['has_more'],
            pendingCounts: $pending['counts'],
            recentTransactions: $recent,
            showSalespersonLink: $user->can('view_referrals'),
            pricesVisible: WebsiteSetting::getPricesVisible(),
            purchaseResumeUrl: PurchaseResumeIntent::resumeUrl(),
            canAddFunds: ($pending['counts']['pending_topups'] ?? 0) === 0,
        );
    }
}
