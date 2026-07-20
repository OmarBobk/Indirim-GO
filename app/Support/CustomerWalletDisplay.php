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
     * Bare secondary money for the compact header stack when a usable facility exists.
     * Debt → remaining available; prepaid/zero → credit limit.
     */
    public function navSecondaryAmount(): ?string
    {
        if (! $this->isCreditActive()) {
            return null;
        }

        if ($this->tone() === 'debt') {
            return $this->formattedAvailableToSpend();
        }

        return $this->formattedCreditLimit();
    }

    /**
     * Labeled secondary line for CTA badges (Limit/Available + amount).
     */
    public function navCreditHint(): ?string
    {
        $amount = $this->navSecondaryAmount();

        if ($amount === null) {
            return null;
        }

        if ($this->tone() === 'debt') {
            return __('messages.wallet_nav_available', [
                'amount' => $amount,
            ]);
        }

        return __('messages.wallet_nav_limit', [
            'amount' => $amount,
        ]);
    }

    /**
     * Single-line badge for the Add Credit CTA (amount + Limit/Available when facility is active).
     */
    public function formattedCtaBadge(): string
    {
        $primary = $this->formattedNavAmount();
        $hint = $this->navCreditHint();

        if ($hint === null) {
            return $primary;
        }

        return $primary.' · '.$hint;
    }

    /**
     * Plain-language rows for the mobile chrome popover (no hover required).
     *
     * @return list<array{label: string, value: string, tone: ?string}>
     */
    public function chromeDetailRows(): array
    {
        $rows = [];

        if ($this->tone() === 'debt') {
            $rows[] = [
                'label' => __('messages.wallet_you_owe'),
                'value' => $this->formattedOutstandingDebt(),
                'tone' => 'debt',
            ];
        } else {
            $rows[] = [
                'label' => __('messages.wallet_prepaid_balance'),
                'value' => $this->formattedBalance(),
                'tone' => $this->tone() === 'positive' ? 'positive' : 'zero',
            ];
        }

        if ($this->isCreditActive()) {
            $rows[] = [
                'label' => __('messages.wallet_credit_limit_label'),
                'value' => $this->formattedCreditLimit(),
                'tone' => null,
            ];
        }

        $rows[] = [
            'label' => __('messages.wallet_available_to_spend'),
            'value' => $this->formattedAvailableToSpend(),
            'tone' => bccomp((string) $this->wallet->availableToSpend(), '0', 2) === 1 ? 'positive' : null,
        ];

        return $rows;
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
