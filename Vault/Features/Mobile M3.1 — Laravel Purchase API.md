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
hash + receipt. Retention **72 hours** (`config('mobile_api.checkout.idempotency_retention_hours')`).
Raw keys never stored/logged; Telescope hides `Idempotency-Key` and `requirements`.

## Side-effect isolation

`CreateFulfillmentsForOrder` after-commit broadcast/notify/automation dispatch
isolated so optional publication cannot turn a committed purchase into HTTP 500.
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
- SQLite cannot prove true parallel DB races; local MySQL verification still recommended for concurrency.

## Related

- [[Mobile M3.0 — Purchasing Architecture]]
- [[Mobile M3.1 Purchase API Contract]]
