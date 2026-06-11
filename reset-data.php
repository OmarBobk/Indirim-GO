<?php

/**
 * Reset transactional test data while preserving users, catalog, and website settings.
 *
 * Usage (from project root):
 *   php artisan tinker
 *   >>> require base_path('reset-data.php');
 *
 * Dry run (counts only, no deletes):
 *   >>> $dryRun = true; require base_path('reset-data.php');
 *
 * Limit to specific users (orders/topups/wallets for those users only):
 *   >>> $userIds = [1, 22, 24]; require base_path('reset-data.php');
 *
 * After running, reconcile wallets if needed:
 *   php artisan wallet:reconcile --dry-run
 *   php artisan wallet:reconcile
 */

use App\Models\Bug;
use App\Models\Commission;
use App\Models\Fulfillment;
use App\Models\Order;
use App\Models\PayoutBatch;
use App\Models\PayoutRequest;
use App\Models\PushLog;
use App\Models\Settlement;
use App\Models\TopupRequest;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** @var bool $dryRun Preview row counts without deleting. */
$dryRun = $dryRun ?? false;

/**
 * User IDs to scope the reset. Empty = wipe all transactional data platform-wide.
 *
 * @var list<int> $userIds
 */
$userIds = $userIds ?? [1];

/** @var bool $clearObservability Clear system_events, notifications, activity log, bugs, push logs. */
$clearObservability = $clearObservability ?? true;

/** @var bool $resetLoyalty Clear loyalty tier fields on affected users. */
$resetLoyalty = $resetLoyalty ?? true;

/** @var bool $clearSessions Truncate the sessions table when using database sessions. */
$clearSessions = $clearSessions ?? true;

$scopeAllUsers = $userIds === [];

/**
 * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
 */
$countOrDelete = function (Builder $query, string $label) use ($dryRun): int {
    $count = (clone $query)->count();

    if (! $dryRun && $count > 0) {
        $query->delete();
    }

    echo sprintf("%s: %d%s\n", $label, $count, $dryRun ? ' (dry run)' : '');

    return $count;
};

$orderIds = Order::query()
    ->when(! $scopeAllUsers, fn (Builder $query) => $query->whereIn('user_id', $userIds))
    ->pluck('id');

$fulfillmentIds = Fulfillment::query()
    ->when(
        $scopeAllUsers,
        fn (Builder $query) => $query,
        fn (Builder $query) => $query->whereIn('order_id', $orderIds)
    )
    ->pluck('id');

$walletIds = Wallet::query()
    ->when(
        $scopeAllUsers,
        fn (Builder $query) => $query,
        fn (Builder $query) => $query->whereIn('user_id', $userIds)
    )
    ->pluck('id');

$settlementIds = Settlement::query()
    ->when(
        $scopeAllUsers,
        fn (Builder $query) => $query,
        fn (Builder $query) => $query->whereHas(
            'fulfillments',
            fn (Builder $fulfillmentQuery) => $fulfillmentQuery->whereIn('fulfillments.id', $fulfillmentIds)
        )
    )
    ->pluck('id');

echo $scopeAllUsers
    ? "Resetting all transactional data...\n"
    : sprintf("Resetting transactional data for user IDs: %s\n", implode(', ', $userIds));

if ($dryRun) {
    echo "DRY RUN — no rows will be deleted.\n";
}

DB::transaction(function () use (
    $clearObservability,
    $clearSessions,
    $countOrDelete,
    $dryRun,
    $fulfillmentIds,
    $orderIds,
    $resetLoyalty,
    $scopeAllUsers,
    $settlementIds,
    $userIds,
    $walletIds,
): void {
    // 1) Payout requests
    $countOrDelete(
        PayoutRequest::query()->when(! $scopeAllUsers, fn (Builder $query) => $query->whereIn('user_id', $userIds)),
        'payout_requests'
    );

    // 2) Commissions (must run before orders / wallet_transactions)
    $countOrDelete(
        Commission::query()->when(
            $scopeAllUsers,
            fn (Builder $query) => $query,
            fn (Builder $query) => $query->where(function (Builder $scoped) use ($orderIds, $userIds): void {
                $scoped->whereIn('order_id', $orderIds)
                    ->orWhereIn('customer_id', $userIds)
                    ->orWhereIn('salesperson_id', $userIds);
            })
        ),
        'commissions'
    );

    // 3) Payout batches (only when wiping everything)
    if ($scopeAllUsers) {
        $countOrDelete(PayoutBatch::query(), 'payout_batches');
    }

    // 4) Wallet ledger rows
    $walletTransactionQuery = WalletTransaction::query();

    if (! $scopeAllUsers) {
        $walletTransactionQuery->where(function (Builder $scoped) use ($settlementIds, $walletIds): void {
            $scoped->whereIn('wallet_id', $walletIds);

            if ($settlementIds->isNotEmpty()) {
                $scoped->orWhere(function (Builder $settlementScoped) use ($settlementIds): void {
                    $settlementScoped
                        ->where('reference_type', Settlement::class)
                        ->whereIn('reference_id', $settlementIds);
                });
            }
        });
    }

    $countOrDelete($walletTransactionQuery, 'wallet_transactions');

    // 5) Settlements (pivot rows cascade)
    $countOrDelete(
        Settlement::query()->when(! $scopeAllUsers, fn (Builder $query) => $query->whereIn('id', $settlementIds)),
        'settlements'
    );

    // 6) Fulfillments (logs + automation runs cascade)
    $countOrDelete(
        Fulfillment::query()->when(! $scopeAllUsers, fn (Builder $query) => $query->whereIn('id', $fulfillmentIds)),
        'fulfillments'
    );

    // 7) Orders (order_items cascade)
    $countOrDelete(
        Order::query()->when(! $scopeAllUsers, fn (Builder $query) => $query->whereIn('id', $orderIds)),
        'orders'
    );

    // 8) Topups (proofs cascade)
    $countOrDelete(
        TopupRequest::query()->when(! $scopeAllUsers, fn (Builder $query) => $query->whereIn('user_id', $userIds)),
        'topup_requests'
    );

    // 9) Zero wallet balances for affected wallets (+ platform wallet on full reset)
    $walletsToZero = Wallet::query()
        ->when(
            $scopeAllUsers,
            fn (Builder $query) => $query,
            fn (Builder $query) => $query->whereIn('id', $walletIds)
        );

    $walletCount = (clone $walletsToZero)->count();

    if (! $dryRun && $walletCount > 0) {
        $walletsToZero->update(['balance' => 0]);
    }

    echo sprintf("wallet balances zeroed: %d%s\n", $walletCount, $dryRun ? ' (dry run)' : '');

    // 10) Loyalty snapshot on users
    if ($resetLoyalty) {
        $loyaltyQuery = DB::table('users');

        if (! $scopeAllUsers) {
            $loyaltyQuery->whereIn('id', $userIds);
        }

        $loyaltyCount = (clone $loyaltyQuery)->count();

        if (! $dryRun && $loyaltyCount > 0) {
            $loyaltyQuery->update([
                'loyalty_tier' => null,
                'loyalty_evaluated_at' => null,
                'loyalty_locked_until' => null,
                'loyalty_override_by' => null,
            ]);
        }

        echo sprintf("user loyalty fields cleared: %d%s\n", $loyaltyCount, $dryRun ? ' (dry run)' : '');
    }

    // 11) Observability / test noise (use query builder where models block deletes)
    if ($clearObservability) {
        if ($scopeAllUsers) {
            $systemEventCount = DB::table('system_events')->count();
            if (! $dryRun && $systemEventCount > 0) {
                DB::table('system_events')->delete();
            }
            echo sprintf("system_events: %d%s\n", $systemEventCount, $dryRun ? ' (dry run)' : '');

            $notificationCount = DB::table('notifications')->count();
            if (! $dryRun && $notificationCount > 0) {
                DB::table('notifications')->delete();
            }
            echo sprintf("notifications: %d%s\n", $notificationCount, $dryRun ? ' (dry run)' : '');

            $activityTable = config('activitylog.table_name', 'activity_log');
            if (DB::getSchemaBuilder()->hasTable($activityTable)) {
                $activityCount = DB::table($activityTable)->count();
                if (! $dryRun && $activityCount > 0) {
                    DB::table($activityTable)->delete();
                }
                echo sprintf("%s: %d%s\n", $activityTable, $activityCount, $dryRun ? ' (dry run)' : '');
            }

            $countOrDelete(Bug::query(), 'bugs');
            $countOrDelete(PushLog::query(), 'push_logs');

            if (DB::getSchemaBuilder()->hasTable('telescope_entries')) {
                $telescopeCount = DB::table('telescope_entries')->count();
                if (! $dryRun && $telescopeCount > 0) {
                    DB::table('telescope_entries')->delete();
                    DB::table('telescope_entries_tags')->delete();
                    DB::table('telescope_monitoring')->delete();
                }
                echo sprintf("telescope_entries: %d%s\n", $telescopeCount, $dryRun ? ' (dry run)' : '');
            }
        } else {
            echo "observability tables skipped in per-user mode (use full reset to clear).\n";
        }
    }

    // 12) Sessions
    if ($clearSessions && $scopeAllUsers && DB::getSchemaBuilder()->hasTable('sessions')) {
        $sessionCount = DB::table('sessions')->count();
        if (! $dryRun && $sessionCount > 0) {
            DB::table('sessions')->truncate();
        }
        echo sprintf("sessions: %d%s\n", $sessionCount, $dryRun ? ' (dry run)' : '');
    }
});

if (! $scopeAllUsers) {
    echo "Per-user reset: run `php artisan wallet:reconcile` to verify platform wallet drift.\n";
} else {
    echo "Done. Run `php artisan wallet:reconcile --dry-run` to confirm zero drift.\n";
}
