<?php

declare(strict_types=1);

namespace App\Support\Financial;

use App\DTOs\Financial\FinancialBalanceDTO;
use App\Enums\CreditFacilityStatus;
use App\Models\Wallet;
use App\Support\Commissions\SalespersonClawbackDebt;

/**
 * Builds the balance snapshot for the customer financial overview.
 */
final class GetCustomerWalletBalanceSummary
{
    public function handle(Wallet $wallet): FinancialBalanceDTO
    {
        $balance = bcadd((string) $wallet->balance, '0', 2);
        $prepaid = bccomp($balance, '0', 2) === 1 ? $balance : '0.00';
        $debt = $wallet->outstandingDebt();
        $creditActive = (bool) $wallet->credit_enabled
            && $wallet->credit_status === CreditFacilityStatus::Active
            && bccomp($wallet->effectiveCreditLimit(), '0', 2) === 1;

        $creditLimit = null;
        $remainingCredit = null;

        if ($creditActive) {
            $creditLimit = bcadd((string) $wallet->credit_limit, '0', 2);
            $remainingCredit = bcsub($creditLimit, $debt, 2);

            if (bccomp($remainingCredit, '0', 2) === -1) {
                $remainingCredit = '0.00';
            }
        }

        $isClawbackDebt = ! $creditActive && (new SalespersonClawbackDebt)->hasOutstandingDebt($wallet);

        return new FinancialBalanceDTO(
            availableToSpend: $wallet->availableToSpend(),
            prepaidBalance: $prepaid,
            outstandingDebt: $debt,
            currency: 'USD',
            creditFacilityActive: $creditActive,
            creditLimit: $creditLimit,
            remainingCredit: $remainingCredit,
            hasOutstandingDebt: bccomp($debt, '0', 2) === 1,
            isClawbackDebt: $isClawbackDebt,
        );
    }
}
