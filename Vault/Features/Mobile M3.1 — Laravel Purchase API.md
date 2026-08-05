---
status: shipped
created: 2026-07-31
updated: 2026-08-05
feature: mobile-m3-1-laravel-purchase-api
---

# Mobile M3.1 — Laravel Purchase API

Customer-only single-line mobile purchasing API on Laravel `staging`.

## Status

**Accepted on Laravel `staging`.** Merged through PR #44 as
`d23f961b1261a01f1adbd5eccfaae454ccfb8045`
(`feat(api): Mobile M3.1 purchase and checkout API (#44)`).
Real local MySQL concurrency gate: **all eight scenarios passed**.
OpenAPI version **1.2.0**. Production deploy and `staging`→`main` remain outside this milestone.

## Goal

Authenticated mobile customers can quote and pay for exactly one product with
wallet funds, including custom amounts and sanitized package requirements, with
durable idempotent receipts and unknown-result recovery.

## Non-goals

- Flutter implementation (delivered later as [[Mobile M3.2 — Flutter Buy-Now Purchasing Flow]])
- Multi-line / server-persisted cart
- Wallet top-ups, refunds, orders history, fulfillment tracking UI
- Production deploy / `staging`→`main`

## Endpoints

Contract: `docs/api/v1/openapi.yaml` **1.2.0** (link; do not duplicate schemas).

| Method | Path | Throttle |
| --- | --- | --- |
| GET | `/api/v1/wallet/summary` | `mobile-purchase-read` |
| POST | `/api/v1/checkout/quote` | `mobile-purchase-read` |
| POST | `/api/v1/checkout` | `mobile-purchase-write` |
| GET | `/api/v1/checkout/status` | `mobile-purchase-read` |
| GET | `/api/v1/orders/{order_number}` | `mobile-purchase-read` |

Additive: `GET /packages/{package}` includes sanitized `requirements`.

## Recorded behaviors (accepted)

- Single-line buy-now API (`items` max 1)
- Wallet summary (`available_to_spend`, affordability inputs)
- Quote + signed `price_fingerprint` (informational; checkout always reprices)
- Required `Idempotency-Key` on checkout (and status recovery)
- Atomic order / wallet debit / fulfillment row creation / attempt completion
- Same-key replay and conflict handling; distinct keys = intentional separate purchases
- Unknown-result status recovery via `GET /checkout/status`
- Sanitized package requirements; Telescope redaction of `Idempotency-Key` and `requirements`
- `prices_visible=false` → `409 purchasing_unavailable` (session retained)
- Owned minimal receipt (`GET /orders/{order_number}` — cross-customer → 404)
- Seventy-two-hour idempotency retention + scheduled pruning (`mobile-checkout:prune-attempts`)

## Financial path

Wraps existing `CheckoutFromPayload` → `CreateOrderFromCartPayload` →
`PayOrderWithWallet` → `CreateFulfillmentsForOrder`. Fingerprint verified before
claiming idempotency / debiting.

## Idempotency

Table `mobile_checkout_attempts`: unique `(user_id, key_hash)`; stores request
hash + receipt. Retention **72 hours** (`config('mobile_api.checkout.idempotency_retention_hours')`),
pruned hourly by `mobile-checkout:prune-attempts`. Retries after retention are
not guaranteed to replay. Raw keys never stored/logged; Telescope hides
`Idempotency-Key` and `requirements`.

Paid order + wallet debit + required fulfillments + attempt `order_id`/completed
commit atomically. Receipt JSON may be reconstructed from the linked owned order.
Stale processing takeover never clears completed linkage; status polling reconciles
linked paid orders. Distinct Idempotency-Keys are distinct purchases (mobile
attempt key hash salts checkout reuse context).

## Side-effect isolation

`CreateFulfillmentsForOrder` after-commit broadcast/notify remain isolated so
optional publication cannot turn a committed purchase into HTTP 500.
Automation dispatch failures are logged as recoverable via
`fulfillment:dispatch-automation` while the durable Queued fulfillment remains.
Authoritative fulfillment row creation remains inside the payment transaction.

## Acceptance criteria

- [x] Merged to `staging` via PR #44 (`d23f961…`)
- [x] OpenAPI 1.2.0 purchase contract
- [x] Focused M3.1 suite + MySQL concurrency gate (8/8) accepted
- [x] Accepted for Flutter M3.2 consumption

## Shipped

- **Date:** 2026-08 (PR #44 → `staging`)
- **Commit:** `d23f961b1261a01f1adbd5eccfaae454ccfb8045`
- **OpenAPI:** `docs/api/v1/openapi.yaml` **1.2.0**
- **Concurrency:** real local MySQL harness — all eight scenarios passed

## Gotchas

- Quote fingerprint is compound signed payload (not cart_hash).
- Idempotency canonical payload is the normalized item only (excludes fingerprint).
- Concurrent same-key in-flight returns `202 checkout_in_progress`.
- Stale unlinked status polling returns `409 checkout_retry_required` (reuse same key).
- Lock order is attempt → order/wallet → complete attempt (purchase and status).
- Quote/fingerprint uses PricingEngine ledger decimal strings (no float sprintf bridge).
- First-claim unique races re-read the winner row (no uncaught 500).
- Exception after commit must re-read durable attempt state before release.
- SQLite cannot prove true parallel DB races; opt-in MySQL harness:
  `MOBILE_CONCURRENCY_TESTS=1` + disposable `*_concurrency` DB +
  `tests/Concurrency/MobileCheckoutConcurrencyHarnessTest.php`.

## Related

- [[Mobile M3.0 — Purchasing Architecture]]
- [[Mobile M3.1 Purchase API Contract]]
- [[Mobile M3.2 — Flutter Buy-Now Purchasing Flow]]
- [[Mobile M3.3 — Local Purchase Integration]]
