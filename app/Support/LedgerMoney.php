<?php

declare(strict_types=1);

namespace App\Support;

use App\Exceptions\InvalidWalletPostingAmountException;

/**
 * Canonical decimal money helper for posted wallet arithmetic (USD, scale 2).
 */
final class LedgerMoney
{
    public const SCALE = 2;

    /** Matches unsigned decimal(10,2) on wallet_transactions.amount. */
    public const MAX_AMOUNT = '99999999.99';

    public const ZERO = '0.00';

    /**
     * Normalize a decimal string to scale 2. Rejects floats' scientific form and malformed values.
     *
     * @throws InvalidWalletPostingAmountException
     */
    public static function normalize(string $amount): string
    {
        $trimmed = trim($amount);

        if ($trimmed === '') {
            throw new InvalidWalletPostingAmountException('Ledger amount must be numeric.');
        }

        if (preg_match('/[eE]/', $trimmed) === 1) {
            throw new InvalidWalletPostingAmountException('Ledger amount must not use scientific notation.');
        }

        if (! preg_match('/^-?\d+(\.\d+)?$/', $trimmed)) {
            throw new InvalidWalletPostingAmountException('Ledger amount must be numeric.');
        }

        return bcadd($trimmed, '0', self::SCALE);
    }

    /**
     * @throws InvalidWalletPostingAmountException
     */
    public static function normalizePositive(string $amount): string
    {
        $normalized = self::normalize($amount);

        if (bccomp($normalized, self::ZERO, self::SCALE) !== 1) {
            throw new InvalidWalletPostingAmountException('Ledger amount must be greater than zero.');
        }

        if (bccomp($normalized, self::MAX_AMOUNT, self::SCALE) === 1) {
            throw new InvalidWalletPostingAmountException('Ledger amount exceeds maximum precision.');
        }

        return $normalized;
    }

    public static function equals(string $left, string $right): bool
    {
        return bccomp(
            self::normalize($left),
            self::normalize($right),
            self::SCALE
        ) === 0;
    }

    public static function add(string $left, string $right): string
    {
        return bcadd(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function sub(string $left, string $right): string
    {
        return bcsub(self::normalize($left), self::normalize($right), self::SCALE);
    }

    public static function compare(string $left, string $right): int
    {
        return bccomp(self::normalize($left), self::normalize($right), self::SCALE);
    }
}
