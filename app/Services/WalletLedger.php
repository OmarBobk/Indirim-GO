<?php

declare(strict_types=1);

namespace App\Services;

use App\DTOs\WalletAdjustmentResult;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Exceptions\IdempotencyConflictException;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Pure ledger engine: lock wallet, post transaction, mutate balance, enforce idempotency.
 * Callers own authorization, validation, activity logs, and domain rules.
 */
final class WalletLedger
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function post(
        Wallet $wallet,
        WalletTransactionType $type,
        WalletTransactionDirection $direction,
        string $amount,
        string $idempotencyKey,
        ?array $meta = null,
        ?string $referenceType = null,
        ?int $referenceId = null,
    ): WalletAdjustmentResult {
        $idempotencyKey = trim($idempotencyKey);

        if ($idempotencyKey === '') {
            throw new InvalidArgumentException('Idempotency key is required for wallet ledger posts.');
        }

        $normalizedAmount = $this->normalizeAmount($amount);

        if (bccomp($normalizedAmount, '0', 2) !== 1) {
            throw new InvalidArgumentException('Ledger amount must be greater than zero.');
        }

        return DB::transaction(function () use (
            $wallet,
            $type,
            $direction,
            $normalizedAmount,
            $idempotencyKey,
            $meta,
            $referenceType,
            $referenceId,
        ): WalletAdjustmentResult {
            $lockedWallet = Wallet::query()
                ->whereKey($wallet->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = WalletTransaction::query()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertSameOperation(
                    $existing,
                    $lockedWallet,
                    $type,
                    $direction,
                    $normalizedAmount,
                    $referenceType,
                    $referenceId,
                );

                return $this->resultFromExisting($existing, $lockedWallet);
            }

            $previousBalance = $this->normalizeAmount((string) $lockedWallet->balance);
            $newBalance = $direction === WalletTransactionDirection::Credit
                ? bcadd($previousBalance, $normalizedAmount, 2)
                : bcsub($previousBalance, $normalizedAmount, 2);

            if ($direction === WalletTransactionDirection::Debit && bccomp($newBalance, '0', 2) === -1) {
                throw new RuntimeException('Insufficient wallet balance for ledger debit.');
            }

            $ledgerMeta = array_merge($meta ?? [], [
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
            ]);

            try {
                $transaction = WalletTransaction::query()->create([
                    'wallet_id' => $lockedWallet->id,
                    'type' => $type,
                    'direction' => $direction,
                    'amount' => $normalizedAmount,
                    'status' => WalletTransaction::STATUS_POSTED,
                    'reference_type' => $referenceType,
                    'reference_id' => $referenceId,
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
                    $type,
                    $direction,
                    $normalizedAmount,
                    $referenceType,
                    $referenceId,
                );

                return $this->resultFromExisting($existing, $lockedWallet);
            }

            $lockedWallet->update([
                'balance' => $newBalance,
            ]);
            $lockedWallet->balance = $newBalance;

            return new WalletAdjustmentResult(
                transaction: $transaction,
                previousBalance: $previousBalance,
                newBalance: $newBalance,
            );
        });
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
        $existingAmount = $this->normalizeAmount((string) $existing->amount);
        $existingReferenceId = $existing->reference_id !== null ? (int) $existing->reference_id : null;

        $matches = (int) $existing->wallet_id === (int) $lockedWallet->id
            && $existing->type === $type
            && $existing->direction === $direction
            && bccomp($existingAmount, $normalizedAmount, 2) === 0
            && $existing->reference_type === $referenceType
            && $existingReferenceId === $referenceId
            && $existing->status === WalletTransaction::STATUS_POSTED;

        if (! $matches) {
            throw new IdempotencyConflictException(
                'Idempotency key already used for a different wallet operation.'
            );
        }
    }

    private function resultFromExisting(WalletTransaction $existing, Wallet $lockedWallet): WalletAdjustmentResult
    {
        $previousBalance = data_get($existing->meta, 'previous_balance');
        $newBalance = data_get($existing->meta, 'new_balance');

        if (! is_string($previousBalance) && ! is_numeric($previousBalance)) {
            $previousBalance = $this->reconstructPreviousBalance($existing, $lockedWallet);
        } else {
            $previousBalance = $this->normalizeAmount((string) $previousBalance);
        }

        if (! is_string($newBalance) && ! is_numeric($newBalance)) {
            $newBalance = $this->normalizeAmount((string) $lockedWallet->balance);
        } else {
            $newBalance = $this->normalizeAmount((string) $newBalance);
        }

        return new WalletAdjustmentResult(
            transaction: $existing,
            previousBalance: $previousBalance,
            newBalance: $newBalance,
        );
    }

    private function reconstructPreviousBalance(WalletTransaction $existing, Wallet $lockedWallet): string
    {
        $current = $this->normalizeAmount((string) $lockedWallet->balance);
        $amount = $this->normalizeAmount((string) $existing->amount);

        if ($existing->direction === WalletTransactionDirection::Credit) {
            return bcsub($current, $amount, 2);
        }

        return bcadd($current, $amount, 2);
    }

    private function normalizeAmount(string $amount): string
    {
        $trimmed = trim($amount);

        if ($trimmed === '' || ! is_numeric($trimmed)) {
            throw new InvalidArgumentException('Ledger amount must be numeric.');
        }

        return bcadd($trimmed, '0', 2);
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
