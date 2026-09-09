---
status: draft
created: 2026-09-09
feature: mobile-m5-laravel-wallet-topups
---

# Mobile M5 — Laravel Wallet and Manual Top-ups

Authenticated mobile customers can read wallet spendable balance, posted ledger
history, active payment methods, and owned top-up requests, and can submit a
manual top-up with optional proof. Submit never credits the wallet.

## Goal

Add additive Mobile API 1.5.0 endpoints that reuse `SubmitCustomerTopupRequest`,
`CreateTopupRequestAction`, `WalletSpendPolicy`, and existing admin
approve/reject + proof rules.

## Constraints

- Sanctum `mobile:access`, `mobile.account`, private/no-store, existing throttles
- Explicit submit `currency` (`USD`|`TRY`); Laravel converts TRY→USD
- Durable hashed Idempotency-Key; same payload replays; different payload 409
- One pending request; proofs private and ownership-scoped
- Decimal amounts remain strings; Money envelope unchanged for wallet USD

## Non-goals

- Flutter UI (mobile repo M5)
- Changing admin approval or Livewire preferred-currency behavior
- Deploy

## Shipped

- `GET /wallet/summary` adds `pending_topup_public_ref`
- `GET /wallet/transactions`, `GET /wallet/payment-methods`
- `GET|POST /wallet/topups`, `GET /wallet/topups/status`
- `GET /wallet/topups/{public_ref}` and `/proof`
- OpenAPI **1.5.0**
