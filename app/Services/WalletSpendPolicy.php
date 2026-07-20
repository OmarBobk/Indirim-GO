<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\WalletSpendDecision;
use App\Enums\WalletSpendFailureReason;
use App\Exceptions\WalletSpendDeniedException;
use App\Models\Wallet;

/**
 * Decides whether a wallet debit is allowed under the current credit facility rules.
 * Pure policy — does not mutate balances or post ledger rows.
 */
final class WalletSpendPolicy
{
    /**
     * Evaluate a proposed debit without throwing.
     */
    public function evaluate(Wallet $wallet, string $amount): WalletSpendDecision
    {
        $normalizedAmount = $this->normalizeAmount($amount);
        $availableToSpend = $wallet->availableToSpend();
        $remainingCredit = $wallet->availableCredit();
        $effectiveCreditLimit = $wallet->effectiveCreditLimit();

        if (bccomp($normalizedAmount, '0', 2) !== 1) {
            return new WalletSpendDecision(
                allowed: false,
                availableToSpend: $availableToSpend,
                remainingCredit: $remainingCredit,
                effectiveCreditLimit: $effectiveCreditLimit,
                failureReason: WalletSpendFailureReason::InvalidAmount,
            );
        }

        if (bccomp($availableToSpend, $normalizedAmount, 2) === -1) {
            return new WalletSpendDecision(
                allowed: false,
                availableToSpend: $availableToSpend,
                remainingCredit: $remainingCredit,
                effectiveCreditLimit: $effectiveCreditLimit,
                failureReason: WalletSpendFailureReason::InsufficientFunds,
            );
        }

        return new WalletSpendDecision(
            allowed: true,
            availableToSpend: $availableToSpend,
            remainingCredit: $remainingCredit,
            effectiveCreditLimit: $effectiveCreditLimit,
            failureReason: null,
        );
    }

    /**
     * @throws WalletSpendDeniedException
     */
    public function assertCanDebit(Wallet $wallet, string $amount): WalletSpendDecision
    {
        $decision = $this->evaluate($wallet, $amount);

        if ($decision->isDenied()) {
            throw new WalletSpendDeniedException($decision);
        }

        return $decision;
    }

    private function normalizeAmount(string $amount): string
    {
        return bcadd($amount, '0', 2);
    }
}
