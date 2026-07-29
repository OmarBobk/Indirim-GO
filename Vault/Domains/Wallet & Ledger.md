# Wallet & Ledger

Authoritative financial model for karman.store.

## Invariants

- **Truth:** `wallets.balance` + `wallet_transactions` — not `system_events` / Activity
- **Mutations:** all **posted** credits/debits via `WalletLedger` (`apply` / `post` / `postCredit` / `postDebit`)
- **Money:** `App\Support\LedgerMoney` — BCMath scale 2, no float for posted amounts
- **Debit floor:** `Wallet::minimumAllowedBalance()` after lock (credit facility aware)
- **Idempotency:** non-empty key + unique DB constraint; conflict → `IdempotencyConflictException`
- **Side effects:** notifications/realtime via `DB::afterCommit` / `CustomerFinancialBroadcaster`
- **Spend checks:** `WalletSpendPolicy` / `availableToSpend()` before debit; kernel re-checks floor under lock
- **Posted immutability:** mechanical fields guarded on `WalletTransaction` model
- **Reconcile:** audit-only by default; `--repair` = audited snapshot (not compensating TX)

## Key files

- `app/Services/WalletLedger.php`, `WalletSpendPolicy.php`
- `app/Support/LedgerMoney.php`, `CustomerFinancialBroadcaster.php`
- `app/DTOs/WalletPosting.php`, `WalletAdjustmentResult.php`
- `app/Actions/Wallets/AdjustWallet.php`, `UpdateCreditFacility.php`
- `app/Actions/Orders/PayOrderWithWallet.php`
- `app/Actions/Topups/ApproveTopupRequest.php`
- `app/Actions/Refunds/ApproveRefundRequest.php`
- `app/Actions/Commissions/CreatePayoutBatch.php`
- `app/Console/Commands/WalletReconcile.php`, `ProfitSettleCommand.php`
- `config/billing.php`

## Features

- [[Customer Financial Centre]] — M6 canonical (M6.0.1 kernel shipped)
- [[Customer Activity]] — wallet events in timeline (projection only)

## Related

- [[Refunds & Settlements]]
- [[Orders & Checkout]]
