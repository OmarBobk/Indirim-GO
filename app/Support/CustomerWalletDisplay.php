<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CreditFacilityStatus;
use App\Models\User;
use App\Models\Wallet;

/**
 * Presentation helpers for customer-facing wallet balance / debt / credit cues.
 * Does not change spend policy — only formats what the customer should see.
 */
final class CustomerWalletDisplay
{
    public function __construct(
        private readonly Wallet $wallet,
        private readonly FrontendMoney $money,
    ) {}

    public static function for(Wallet $wallet, ?User $user = null): self
    {
        return new self($wallet, FrontendMoney::for($user ?? $wallet->user));
    }

    public function wallet(): Wallet
    {
        return $this->wallet;
    }

    /**
     * @return 'debt'|'positive'|'zero'
     */
    public function tone(): string
    {
        if ($this->wallet->isOverdrawn()) {
            return 'debt';
        }

        if (bccomp((string) $this->wallet->balance, '0', 2) === 1) {
            return 'positive';
        }

        return 'zero';
    }

    public function isCreditActive(): bool
    {
        return (bool) $this->wallet->credit_enabled
            && $this->wallet->credit_status === CreditFacilityStatus::Active;
    }

    public function isCreditGranted(): bool
    {
        return (bool) $this->wallet->credit_enabled;
    }

    /**
     * Signed ledger balance for glanceable cash vs debt (not available-to-spend).
     */
    public function formattedBalance(): string
    {
        return $this->money->format((float) $this->wallet->balance, 'USD', 2);
    }

    public function formattedOutstandingDebt(): string
    {
        return $this->money->format((float) $this->wallet->outstandingDebt(), 'USD', 2);
    }

    public function formattedAvailableToSpend(): string
    {
        return $this->money->format((float) $this->wallet->availableToSpend(), 'USD', 2);
    }

    public function formattedCreditLimit(): string
    {
        return $this->money->format((float) $this->wallet->credit_limit, 'USD', 2);
    }

    /**
     * Compact primary amount for nav/CTA: signed balance (owes = negative).
     */
    public function formattedNavAmount(): string
    {
        return $this->formattedBalance();
    }

    public function amountTextClass(): string
    {
        return match ($this->tone()) {
            'debt' => 'text-red-700 dark:text-red-400',
            'positive' => 'text-emerald-700 dark:text-emerald-400',
            'zero' => 'text-zinc-700 dark:text-zinc-300',
        };
    }

    /**
     * Flux badge color name for the "Add sufficient" CTA.
     */
    public function badgeColor(): string
    {
        return match ($this->tone()) {
            'debt' => 'red',
            'positive' => 'green',
            'zero' => 'zinc',
        };
    }

    /**
     * Short secondary line for nav when a usable facility exists and the customer is not in debt focus.
     */
    public function navCreditHint(): ?string
    {
        if (! $this->isCreditActive()) {
            return null;
        }

        if ($this->tone() === 'debt') {
            return __('messages.wallet_nav_available', [
                'amount' => $this->formattedAvailableToSpend(),
            ]);
        }

        return __('messages.wallet_nav_limit', [
            'amount' => $this->formattedCreditLimit(),
        ]);
    }

    /**
     * Title / aria context for the compact nav amount.
     */
    public function navTitle(): string
    {
        if ($this->tone() === 'debt') {
            $parts = [
                __('messages.wallet_you_owe').': '.$this->formattedOutstandingDebt(),
            ];

            if ($this->isCreditActive()) {
                $parts[] = __('messages.wallet_available_to_spend').': '.$this->formattedAvailableToSpend();
            }

            return implode(' · ', $parts);
        }

        $parts = [
            __('messages.wallet_prepaid_balance').': '.$this->formattedBalance(),
        ];

        if ($this->isCreditActive()) {
            $parts[] = __('messages.wallet_credit_limit_label').': '.$this->formattedCreditLimit();
            $parts[] = __('messages.wallet_available_to_spend').': '.$this->formattedAvailableToSpend();
        }

        return implode(' · ', $parts);
    }
}
