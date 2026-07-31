---
status: in_review
created: 2026-07-31
feature: mobile-m3-1-laravel-purchase-api
---

# Mobile M3.1 — Laravel Purchase API

Customer-only single-line mobile purchasing API on Laravel `staging`.

## Goal

Authenticated mobile customers can quote and pay for exactly one product with
wallet funds, including custom amounts and sanitized package requirements, with
durable idempotent receipts and unknown-result recovery.

## Non-goals

- Flutter implementation
- Multi-line / server-persisted cart
- Wallet top-ups, refunds, orders history, fulfillment tracking UI
- Production deploy / `staging`→`main`

## Endpoints

| Method | Path | Throttle |
| --- | --- | --- |
| GET | `/api/v1/wallet/summary` | `mobile-purchase-read` |
| POST | `/api/v1/checkout/quote` | `mobile-purchase-read` |
| POST | `/api/v1/checkout` | `mobile-purchase-write` |
| GET | `/api/v1/checkout/status` | `mobile-purchase-read` |
| GET | `/api/v1/orders/{order_number}` | `mobile-purchase-read` |

Additive: `GET /packages/{package}` now includes sanitized `requirements`.

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

- [ ] Draft PR on `staging` for Omar review
- [ ] OpenAPI 1.2.0 fidelity tests green
- [ ] Focused M3.1 Pest suite green
- [ ] Not marked accepted until Omar review

## Shipped

<!-- Fill after Omar accepts -->

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
