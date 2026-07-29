<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\WalletAdjustmentResult;
use App\DTOs\WalletPosting;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\InsufficientWalletBalanceException;
use App\Exceptions\InvalidPendingPromotionException;
use App\Exceptions\InvalidWalletPostingAmountException;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Support\LedgerMoney;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pure ledger engine: lock wallet, post or promote transaction, mutate balance, enforce idempotency.
 * Callers own authorization, source-state transitions, notifications, and domain rules.
 *
 * Lock order (callers): workflow source → wallet (kernel locks wallet + TX rows only).
 */
final class WalletLedger
{
    public const KERNEL_VERSION = 'm6.0.1';

    /**
     * Post a wallet credit/debit.
     *
     * Prefer WalletPosting. Named-argument form is kept for call-site compatibility.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function post(
        Wallet|WalletPosting $wallet,
        ?WalletTransactionType $type = null,
        ?WalletTransactionDirection $direction = null,
        ?string $amount = null,
        ?string $idempotencyKey = null,
        ?array $meta = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?WalletTransaction $pendingTransaction = null,
        ?string $minimumAllowedBalance = null,
    ): WalletAdjustmentResult {
        if ($wallet instanceof WalletPosting) {
            return $this->apply($wallet);
        }

        if ($type === null || $direction === null || $amount === null || $idempotencyKey === null) {
            throw new InvalidWalletPostingAmountException('Wallet posting requires type, direction, amount, and idempotency key.');
        }

        return $this->apply(new WalletPosting(
            wallet: $wallet,
            type: $type,
            direction: $direction,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            meta: $meta,
            referenceType: $referenceType,
            referenceId: $referenceId,
            pendingTransaction: $pendingTransaction,
            minimumAllowedBalance: $minimumAllowedBalance,
        ));
    }

    public function apply(WalletPosting $posting): WalletAdjustmentResult
    {
        $idempotencyKey = trim($posting->idempotencyKey);

        if ($idempotencyKey === '') {
            throw new InvalidWalletPostingAmountException('Idempotency key is required for wallet ledger posts.');
        }

        $normalizedAmount = LedgerMoney::normalizePositive($posting->amount);

        return DB::transaction(function () use ($posting, $normalizedAmount, $idempotencyKey): WalletAdjustmentResult {
            $lockedWallet = Wallet::query()
                ->whereKey($posting->wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existingByKey = WalletTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingByKey !== null) {
                $this->assertSameOperation(
                    $existingByKey,
                    $lockedWallet,
                    $posting->type,
                    $posting->direction,
                    $normalizedAmount,
                    $posting->referenceType,
                    $posting->referenceId,
                );

                return $this->resultFromExisting($existingByKey, $lockedWallet, wasReplayed: true);
            }

            if ($posting->pendingTransaction !== null) {
                return $this->promotePending(
                    $posting,
                    $lockedWallet,
                    $normalizedAmount,
                    $idempotencyKey,
                );
            }

            return $this->createPosted(
                $posting,
                $lockedWallet,
                $normalizedAmount,
                $idempotencyKey,
            );
        });
    }

    /**
     * Backward-compatible named-argument entry used by AdjustWallet and callers.
     *
     * @param  array<string, mixed>|null  $meta
     */
    public function postCredit(
        Wallet $wallet,
        WalletTransactionType $type,
        string $amount,
        string $idempotencyKey,
        ?array $meta = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?WalletTransaction $pendingTransaction = null,
    ): WalletAdjustmentResult {
        return $this->apply(new WalletPosting(
            wallet: $wallet,
            type: $type,
            direction: WalletTransactionDirection::Credit,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            meta: $meta,
            referenceType: $referenceType,
            referenceId: $referenceId,
            pendingTransaction: $pendingTransaction,
        ));
    }

    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function postDebit(
        Wallet $wallet,
        WalletTransactionType $type,
        string $amount,
        string $idempotencyKey,
        ?array $meta = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?WalletTransaction $pendingTransaction = null,
        ?string $minimumAllowedBalance = null,
    ): WalletAdjustmentResult {
        return $this->apply(new WalletPosting(
            wallet: $wallet,
            type: $type,
            direction: WalletTransactionDirection::Debit,
            amount: $amount,
            idempotencyKey: $idempotencyKey,
            meta: $meta,
            referenceType: $referenceType,
            referenceId: $referenceId,
            pendingTransaction: $pendingTransaction,
            minimumAllowedBalance: $minimumAllowedBalance,
        ));
    }

    private function createPosted(
        WalletPosting $posting,
        Wallet $lockedWallet,
        string $normalizedAmount,
        string $idempotencyKey,
    ): WalletAdjustmentResult {
        [$previousBalance, $newBalance] = $this->computeBalances(
            $lockedWallet,
            $posting->direction,
            $normalizedAmount,
            $posting->minimumAllowedBalance,
        );

        $ledgerMeta = $this->buildPostedMeta($posting->meta, $previousBalance, $newBalance);

        try {
            $transaction = WalletTransaction::query()->create([
                'wallet_id' => $lockedWallet->id,
                'type' => $posting->type,
                'direction' => $posting->direction,
                'amount' => $normalizedAmount,
                'status' => WalletTransaction::STATUS_POSTED,
                'reference_type' => $posting->referenceType,
                'reference_id' => $posting->referenceId,
                'idempotency_key' => $idempotencyKey,
                'meta' => $ledgerMeta,
            ]);
        } catch (QueryException $exception) {
            if (! $this->isUniqueIdempotencyConstraint($exception, $idempotencyKey)) {
                throw $exception;
            }

            $existing = WalletTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameOperation(
                $existing,
                $lockedWallet,
                $posting->type,
                $posting->direction,
                $normalizedAmount,
                $posting->referenceType,
                $posting->referenceId,
            );

            return $this->resultFromExisting($existing, $lockedWallet, wasReplayed: true);
        }

        $lockedWallet->update(['balance' => $newBalance]);
        $lockedWallet->balance = $newBalance;

        return new WalletAdjustmentResult(
            transaction: $transaction,
            previousBalance: $previousBalance,
            newBalance: $newBalance,
            wallet: $lockedWallet,
            wasReplayed: false,
            wasPromoted: false,
        );
    }

    private function promotePending(
        WalletPosting $posting,
        Wallet $lockedWallet,
        string $normalizedAmount,
        string $idempotencyKey,
    ): WalletAdjustmentResult {
        $pending = WalletTransaction::query()
            ->whereKey($posting->pendingTransaction->id)
            ->lockForUpdate()
            ->firstOrFail();

        if ($pending->status === WalletTransaction::STATUS_POSTED) {
            $this->assertSameOperation(
                $pending,
                $lockedWallet,
                $posting->type,
                $posting->direction,
                $normalizedAmount,
                $posting->referenceType,
                $posting->referenceId,
            );

            return $this->resultFromExisting($pending, $lockedWallet, wasReplayed: true, wasPromoted: true);
        }

        if ($pending->status !== WalletTransaction::STATUS_PENDING) {
            throw new InvalidPendingPromotionException('Only pending wallet transactions can be promoted.');
        }

        if ((int) $pending->wallet_id !== (int) $lockedWallet->id) {
            throw new InvalidPendingPromotionException('Pending transaction wallet mismatch.');
        }

        if ($pending->type !== $posting->type || $pending->direction !== $posting->direction) {
            throw new InvalidPendingPromotionException('Pending transaction type or direction mismatch.');
        }

        $pendingAmount = LedgerMoney::normalizePositive((string) $pending->amount);
        if (! LedgerMoney::equals($pendingAmount, $normalizedAmount)) {
            throw new InvalidPendingPromotionException('Pending transaction amount mismatch.');
        }

        if (
            $posting->referenceType !== null
            && (
                $pending->reference_type !== $posting->referenceType
                || (int) $pending->reference_id !== (int) $posting->referenceId
            )
        ) {
            throw new InvalidPendingPromotionException('Pending transaction reference mismatch.');
        }

        if ($pending->idempotency_key !== null && $pending->idempotency_key !== '' && $pending->idempotency_key !== $idempotencyKey) {
            throw new IdempotencyConflictException(
                'Pending transaction already has a different idempotency key.'
            );
        }

        [$previousBalance, $newBalance] = $this->computeBalances(
            $lockedWallet,
            $posting->direction,
            $normalizedAmount,
            $posting->minimumAllowedBalance,
        );

        $ledgerMeta = $this->buildPostedMeta(
            array_merge($pending->meta ?? [], $posting->meta ?? []),
            $previousBalance,
            $newBalance,
        );

        try {
            $pending->forceFill([
                'status' => WalletTransaction::STATUS_POSTED,
                'idempotency_key' => $idempotencyKey,
                'meta' => $ledgerMeta,
            ])->save();
        } catch (QueryException $exception) {
            if (! $this->isUniqueIdempotencyConstraint($exception, $idempotencyKey)) {
                throw $exception;
            }

            $existing = WalletTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing === null) {
                throw $exception;
            }

            $this->assertSameOperation(
                $existing,
                $lockedWallet,
                $posting->type,
                $posting->direction,
                $normalizedAmount,
                $posting->referenceType,
                $posting->referenceId,
            );

            return $this->resultFromExisting($existing, $lockedWallet, wasReplayed: true);
        }

        $lockedWallet->update(['balance' => $newBalance]);
        $lockedWallet->balance = $newBalance;

        return new WalletAdjustmentResult(
            transaction: $pending->refresh(),
            previousBalance: $previousBalance,
            newBalance: $newBalance,
            wallet: $lockedWallet,
            wasReplayed: false,
            wasPromoted: true,
        );
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function computeBalances(
        Wallet $lockedWallet,
        WalletTransactionDirection $direction,
        string $normalizedAmount,
        ?string $minimumAllowedBalance,
    ): array {
        $previousBalance = LedgerMoney::normalize((string) $lockedWallet->balance);
        $newBalance = $direction === WalletTransactionDirection::Credit
            ? LedgerMoney::add($previousBalance, $normalizedAmount)
            : LedgerMoney::sub($previousBalance, $normalizedAmount);

        if ($direction === WalletTransactionDirection::Debit) {
            $floor = $minimumAllowedBalance !== null
                ? LedgerMoney::normalize($minimumAllowedBalance)
                : LedgerMoney::normalize($lockedWallet->minimumAllowedBalance());

            if (LedgerMoney::compare($newBalance, $floor) === -1) {
                throw new InsufficientWalletBalanceException(
                    message: 'Insufficient wallet balance for ledger debit.',
                    balanceAfter: $newBalance,
                    minimumAllowedBalance: $floor,
                );
            }
        }

        return [$previousBalance, $newBalance];
    }

    /**
     * @param  array<string, mixed>|null  $meta
     * @return array<string, mixed>
     */
    private function buildPostedMeta(?array $meta, string $previousBalance, string $newBalance): array
    {
        return array_merge($meta ?? [], [
            'previous_balance' => $previousBalance,
            'new_balance' => $newBalance,
            'balance_before' => $previousBalance,
            'balance_after' => $newBalance,
            'ledger_kernel' => self::KERNEL_VERSION,
        ]);
    }

    private function assertSameOperation(
        WalletTransaction $existing,
        Wallet $lockedWallet,
        WalletTransactionType $type,
        WalletTransactionDirection $direction,
        string $normalizedAmount,
        ?string $referenceType,
        ?int $referenceId,
    ): void {
        $existingAmount = LedgerMoney::normalize((string) $existing->amount);
        $existingReferenceId = $existing->reference_id !== null ? (int) $existing->reference_id : null;

        $matches = (int) $existing->wallet_id === (int) $lockedWallet->id
            && $existing->type === $type
            && $existing->direction === $direction
            && LedgerMoney::equals($existingAmount, $normalizedAmount)
            && $existing->reference_type === $referenceType
            && $existingReferenceId === $referenceId
            && $existing->status === WalletTransaction::STATUS_POSTED;

        if (! $matches) {
            Log::warning('Wallet ledger idempotency conflict', [
                'operation' => 'wallet_ledger_post',
                'wallet_id' => $lockedWallet->id,
                'idempotency_key_hash' => hash('sha256', (string) $existing->idempotency_key),
                'existing_type' => $existing->type?->value,
                'requested_type' => $type->value,
                'exception' => IdempotencyConflictException::class,
            ]);

            throw new IdempotencyConflictException(
                'Idempotency key already used for a different wallet operation.'
            );
        }
    }

    private function resultFromExisting(
        WalletTransaction $existing,
        Wallet $lockedWallet,
        bool $wasReplayed = true,
        bool $wasPromoted = false,
    ): WalletAdjustmentResult {
        $previousBalance = data_get($existing->meta, 'balance_before')
            ?? data_get($existing->meta, 'previous_balance');
        $newBalance = data_get($existing->meta, 'balance_after')
            ?? data_get($existing->meta, 'new_balance');

        if (! is_string($previousBalance) && ! is_numeric($previousBalance)) {
            $previousBalance = $this->reconstructPreviousBalance($existing, $lockedWallet);
        } else {
            $previousBalance = LedgerMoney::normalize((string) $previousBalance);
        }

        if (! is_string($newBalance) && ! is_numeric($newBalance)) {
            $newBalance = LedgerMoney::normalize((string) $lockedWallet->balance);
        } else {
            $newBalance = LedgerMoney::normalize((string) $newBalance);
        }

        return new WalletAdjustmentResult(
            transaction: $existing,
            previousBalance: $previousBalance,
            newBalance: $newBalance,
            wallet: $lockedWallet,
            wasReplayed: $wasReplayed,
            wasPromoted: $wasPromoted,
        );
    }

    private function reconstructPreviousBalance(WalletTransaction $existing, Wallet $lockedWallet): string
    {
        $current = LedgerMoney::normalize((string) $lockedWallet->balance);
        $amount = LedgerMoney::normalize((string) $existing->amount);

        if ($existing->direction === WalletTransactionDirection::Credit) {
            return LedgerMoney::sub($current, $amount);
        }

        return LedgerMoney::add($current, $amount);
    }

    private function isUniqueIdempotencyConstraint(QueryException $exception, string $idempotencyKey): bool
    {
        if ((string) $exception->getCode() !== '23000') {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'idempotency_key')
            || str_contains($message, $idempotencyKey);
    }
}
