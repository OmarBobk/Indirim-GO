<?php

declare(strict_types=1);

namespace App\Actions\AiAssistant;

use App\Models\User;
use App\Models\WalletTransaction;

class FetchWalletData
{
    /**
     * @return array{
     *     user: array{id: int, username: string, name: string, email: string},
     *     wallet: array{id: int, currency: string, balance: string}|null,
     *     recent_transactions: list<array{
     *         id: int,
     *         type: string,
     *         direction: string,
     *         amount: string,
     *         status: string,
     *         reference_type: string|null,
     *         reference_id: int|null,
     *         created_at: string,
     *     }>,
     * }|null
     */
    public function handle(string $usernameOrId): ?array
    {
        $needle = trim($usernameOrId);

        if ($needle === '') {
            return null;
        }

        $user = ctype_digit($needle)
            ? User::query()->find((int) $needle)
            : User::query()->where('username', $needle)->first();

        if ($user === null) {
            return null;
        }

        $wallet = $user->wallet;

        $recentTransactions = [];

        if ($wallet !== null) {
            $recentTransactions = $wallet->transactions()
                ->where('status', WalletTransaction::STATUS_POSTED)
                ->latest('created_at')
                ->limit(10)
                ->get()
                ->map(fn (WalletTransaction $transaction): array => [
                    'id' => $transaction->id,
                    'type' => $transaction->type->value,
                    'direction' => $transaction->direction->value,
                    'amount' => (string) $transaction->amount,
                    'status' => $transaction->status,
                    'reference_type' => $transaction->reference_type,
                    'reference_id' => $transaction->reference_id,
                    'created_at' => $transaction->created_at?->toDateTimeString() ?? '',
                ])
                ->values()
                ->all();
        }

        return [
            'user' => [
                'id' => $user->id,
                'username' => (string) $user->username,
                'name' => (string) $user->name,
                'email' => (string) $user->email,
            ],
            'wallet' => $wallet === null ? null : [
                'id' => $wallet->id,
                'currency' => $wallet->currency,
                // Financial read-only — source of truth: wallets.balance
                'balance' => (string) $wallet->balance,
            ],
            'recent_transactions' => $recentTransactions,
        ];
    }
}
