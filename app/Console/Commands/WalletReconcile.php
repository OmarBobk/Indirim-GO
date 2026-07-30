<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\CustomerFinancialInvalidationReason;
use App\Enums\WalletTransactionDirection;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Notifications\WalletReconciledNotification;
use App\Services\NotificationRecipientService;
use App\Services\OperationalIntelligenceService;
use App\Support\CustomerFinancialBroadcaster;
use App\Support\LedgerMoney;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Audit wallet balances against posted ledger sum.
 *
 * Default: audit-only (no mutation).
 * --repair: controlled snapshot set balance = ledger sum (ops-only).
 *
 * Note: a compensating WalletLedger post cannot close drift — posting changes both
 * balance and the posted-sum by the same amount, leaving diff unchanged.
 */
class WalletReconcile extends Command
{
    protected $signature = 'wallet:reconcile
                            {--user= : Only reconcile wallets for a user ID}
                            {--dry-run : Alias for audit-only (default behaviour)}
                            {--repair : Ops-only snapshot repair: set balance to posted ledger sum with audit}';

    protected $description = 'Audit wallet balances against posted transactions (snapshot repair only with --repair)';

    public function handle(): int
    {
        $userId = $this->option('user');
        $repair = (bool) $this->option('repair');

        if ($userId !== null && ! ctype_digit((string) $userId)) {
            $this->error('Invalid user id.');

            return self::FAILURE;
        }

        $wallets = Wallet::query()
            ->when($userId !== null, fn ($query) => $query->where('user_id', (int) $userId))
            ->get();

        if ($wallets->isEmpty()) {
            $this->line('No wallets found.');

            return self::SUCCESS;
        }

        $hasDrift = false;
        $repaired = 0;

        foreach ($wallets as $wallet) {
            $result = DB::transaction(function () use ($wallet, $repair): ?array {
                $lockedWallet = Wallet::query()
                    ->whereKey($wallet->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $expectedRaw = WalletTransaction::query()
                    ->where('wallet_id', $lockedWallet->id)
                    ->where('status', WalletTransaction::STATUS_POSTED)
                    ->selectRaw(
                        'COALESCE(SUM(CASE WHEN direction = ? THEN amount ELSE -amount END), 0) as balance',
                        [WalletTransactionDirection::Credit->value]
                    )
                    ->value('balance');

                $expected = LedgerMoney::normalize((string) $expectedRaw);
                $stored = LedgerMoney::normalize((string) $lockedWallet->balance);
                $diff = LedgerMoney::sub($expected, $stored);

                if (LedgerMoney::compare($diff, LedgerMoney::ZERO) === 0) {
                    return null;
                }

                $walletForDrift = $lockedWallet;
                $driftMeta = [
                    'stored' => $stored,
                    'expected' => $expected,
                    'diff' => $diff,
                ];
                DB::afterCommit(function () use ($walletForDrift, $driftMeta): void {
                    app(OperationalIntelligenceService::class)->detectReconciliationDrift($walletForDrift, $driftMeta);
                });

                if (! $repair) {
                    return [
                        'stored' => $stored,
                        'expected' => $expected,
                        'diff' => $diff,
                        'repaired' => false,
                    ];
                }

                // Policy C: snapshot repair — ledger sum is authoritative; no new TX
                // (a ledger post cannot close balance-vs-sum drift).
                $lockedWallet->update(['balance' => $expected]);
                CustomerFinancialBroadcaster::dispatch(
                    (int) $lockedWallet->user_id,
                    CustomerFinancialInvalidationReason::BalanceChanged,
                );

                activity()
                    ->inLog('payments')
                    ->event('wallet.reconciled')
                    ->performedOn($lockedWallet)
                    ->withProperties([
                        'wallet_id' => $lockedWallet->id,
                        'user_id' => $lockedWallet->user_id,
                        'stored_balance' => $stored,
                        'expected_balance' => $expected,
                        'diff' => $diff,
                        'repair_mode' => 'snapshot',
                    ])
                    ->log('Wallet reconciled via audited snapshot repair');

                $notification = WalletReconciledNotification::fromWallet(
                    $lockedWallet->fresh(),
                    (float) $stored,
                    (float) $expected,
                    (float) $diff,
                );
                app(NotificationRecipientService::class)->adminUsers()->each(fn ($admin) => $admin->notify($notification));

                return [
                    'stored' => $stored,
                    'expected' => $expected,
                    'diff' => $diff,
                    'repaired' => true,
                ];
            });

            if ($result === null) {
                continue;
            }

            $hasDrift = true;
            $suffix = $result['repaired'] ? ' [repaired]' : ' [audit-only]';
            $this->line(sprintf(
                'Wallet %d (user %d): stored=%s expected=%s diff=%s%s',
                $wallet->id,
                $wallet->user_id,
                $result['stored'],
                $result['expected'],
                $result['diff'],
                $suffix,
            ));

            if ($result['repaired']) {
                $repaired++;
            }
        }

        if (! $hasDrift) {
            $this->info('No drift detected.');
        } elseif ($repair) {
            $this->info(sprintf('Repaired %d wallet(s) via audited snapshot.', $repaired));
        } else {
            $this->warn('Drift detected (audit-only). Re-run with --repair for audited snapshot repair.');
        }

        return self::SUCCESS;
    }
}
