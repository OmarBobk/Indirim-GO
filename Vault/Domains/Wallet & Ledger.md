# Wallet & Ledger

Authoritative financial model for karman.store.

## Invariants

- **Truth:** `wallets.balance` + `wallet_transactions` — not `system_events`
- **Mutations:** transactional, `lockForUpdate`, idempotency keys
- **Side effects:** notifications/realtime via `DB::afterCommit`
- **Spend checks:** `WalletSpendPolicy` / `availableToSpend()` — customer may have negative balance under credit facility

## Key files

- `app/Services/WalletLedger.php`, `WalletSpendPolicy.php`
- `app/Actions/Wallets/AdjustWallet.php`, `UpdateCreditFacility.php`
- `app/Actions/Topups/ApproveTopupRequest.php`
- `config/billing.php`

## Features

- [[Customer Activity]] — wallet events in timeline

## Related

- [[Refunds & Settlements]]
- [[Orders & Checkout]]
