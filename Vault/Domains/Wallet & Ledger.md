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
- **Posted immutability:** mechanical fields guarded on `WalletTransaction` model (includes `public_ref`, `posted_at`)
- **Reconcile:** audit-only by default; `--repair` = audited snapshot (not compensating TX)
- **Customer ledger (M6.2):** posted rows only; order by `posted_at` then `id`; public ref `WTX-*`
- **Top-up workflow (M6.3):** `TopupRequest` + `TUP-*` public_ref; pending TX ≠ posted money; TRY→USD locked at submission; one pending per user
- **Transaction detail / receipt (M6.5):** owned posted `WTX-*` detail; snapshot-first `meta.receipt`; printable HTML only (no server PDF)

## Key files

- `app/Services/WalletLedger.php`, `WalletSpendPolicy.php`
- `app/Support/LedgerMoney.php`, `CustomerFinancialBroadcaster.php`, `WalletTransactionPublicRef.php`, `TopupRequestPublicRef.php`
- `app/DTOs/WalletPosting.php`, `WalletAdjustmentResult.php`
- `app/Actions/Wallets/AdjustWallet.php`, `UpdateCreditFacility.php`
- `app/Actions/Orders/PayOrderWithWallet.php`
- `app/Actions/Topups/ApproveTopupRequest.php`, `CreateTopupRequestAction.php`, `SubmitCustomerTopupRequest.php`
- `app/Actions/Topups/GetCustomerTopupRequests.php`, `GetCustomerTopupDetail.php`
- `app/Support/CustomerTopupPresenter.php`, `FinancialDestinationResolver.php`
- `app/Actions/Refunds/ApproveRefundRequest.php`
- `app/Actions/Commissions/CreatePayoutBatch.php`
- `app/Console/Commands/WalletReconcile.php`, `ProfitSettleCommand.php`
- **M6.1 overview:** `GetCustomerFinancialOverview`, `app/Support/Financial/*`, `CustomerFinancialPresenter`
- **M6.2 ledger:** `GetCustomerWalletTransactions`, `CustomerWalletTransactionPresenter`, `app/DTOs/Financial/WalletTransaction*`
- **M6.5 detail:** `GetCustomerTransactionDetail`, `CustomerTransactionDetailDTO`, `CustomerTransactionDetailPresenter`, `ReceiptSnapshot`, `x-wallet.transaction-*`
- `resources/js/customer-financial-invalidation.js`
- `config/billing.php`

## Features

- [[Customer Financial Centre]] — through **M6.5** transaction detail + printable receipt shipped
- [[Customer Activity]] — projection only

## Customer surfaces

- `/wallet` = Financial Overview
- `/wallet/transactions` = posted ledger
- `/wallet/transactions/{WTX-*}` = transaction detail + printable receipt
- `/wallet/topups` = top-up workflow list
- `/wallet/topups/{TUP-*}` = top-up detail
- `/wallet/topup` = create / corrected retry
- `/wallet/refunds` = refund workflow list
- `/wallet/refunds/{WTX-*}` = refund detail
- Realtime: invalidation only on existing user private channel

## Related

- [[Refunds & Settlements]]
- [[Orders & Checkout]]
