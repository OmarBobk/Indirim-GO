---
title: Mobile M3.2 — Flutter Buy-Now Purchasing Flow
type: feature
status: shipped
owner: Omar
project: İndirimGo
tags: [mobile, flutter, purchase, checkout, m3]
created: 2026-08-05
updated: 2026-08-05
---

# Mobile M3.2 — Flutter Buy-Now Purchasing Flow

## Summary

Flutter customer buy-now purchasing on `OmarBobk/indirimGo-mobile` `origin/main`, consuming the accepted Mobile M3.1 Laravel purchase API (OpenAPI **1.2.0**). Package detail → buy form → quote → review → wallet confirm → receipt. No cart, top-up, full order history, or fulfillment tracking.

## Status

**Accepted on mobile `main`.** Merged through PR #5 (squash) as `9e056f1d5d795c8ad9d9c4061a0fddac831bae6f`. That merge includes the M3.2 implementation, formatting, and the final recovery correction (pre-squash tip `5680c65` — customer-scoped pending store, durable completed `order_number` anchor, receipt-before-key-clear, A→B isolation). Flutter CI: **197 tests** green before merge.

## Depends on

- [[Mobile M3.0 — Purchasing Architecture]] — accepted architecture
- [[Mobile M3.1 — Laravel Purchase API]] — accepted staging API
- [[Mobile M3.1 Purchase API Contract]] — accepted contract
- OpenAPI `docs/api/v1/openapi.yaml` **1.2.0** (purchase paths)
- Mobile architecture: `docs/architecture/m3.2-purchase-flow.md` on `indirimGo-mobile` (authoritative client detail; do not duplicate schemas here)

## Flow

1. **Package detail** — Buy Now when `can_purchase` and authenticated; otherwise existing auth/visibility gating.
2. **Buy form** — Fixed `unit_price` or custom amount (≥ min); text / number / select package requirements with local validation only.
3. **Quote** — `POST /checkout/quote`; display server `line_total`, `currency`, `price_fingerprint`; invalidate quote on amount/requirement edits.
4. **Review** — Server quote totals + wallet summary (`GET /wallet/summary`); Confirm enabled only when `can_afford`.
5. **Confirm** — Client UUID `Idempotency-Key` + `price_fingerprint` + sanitized requirements → `POST /checkout`.
6. **Receipt** — Owned order from create or `GET /orders/{order_number}`; show `order_number`, totals, status, line summary. No fulfillment tracking UI.
7. **Unknown result** — Bounded polling via `GET /checkout/status` with the same `Idempotency-Key`.

## Client invariants (accepted)

| Rule | Behavior |
|------|----------|
| No client price authority | Never recalculate Laravel prices; never parse money to `double` for decisions |
| Decimal money | Display API decimal strings as-is; render money LTR |
| Pending recovery | Secure storage scoped per authenticated customer id |
| Durable success | Persist completed `order_number` before clearing pending key; recover receipt if process dies after success |
| Receipt-before-clear | Clear pending key only after receipt is shown or durable order anchor is stored |
| A→B isolation | Account switch must not resume another user's pending purchase |
| Unknown result | Bounded status polling via `GET /checkout/status` |
| 401 vs offline | 401 clears session; connectivity/timeout/5xx retain session and pending key for retry |
| i18n / a11y | Arabic + English ARB; RTL/LTR; TalkBack-oriented semantics |
| Requirement privacy | Values never in logs, `toString`, receipts, or pending storage |

## Explicit non-goals (M3.2)

- Multi-line cart / cart persistence
- Wallet top-up or payment-method UI
- Full order history list
- Fulfillment / supplier tracking UI
- Refunds, cancellations, push notifications
- Production deployment

## Related

- [[Mobile M3.3 — Local Purchase Integration]] — accepted local Android walkthrough
- [[Mobile M3.1 — Laravel Purchase API]]

## Acceptance

- [x] Buy-now path for fixed and custom-amount products with requirements
- [x] Quote → review → confirm → receipt against M3.1 contract
- [x] Idempotent recovery and A→B isolation
- [x] 197 tests; PR #5 merged to `main`
- [x] No cart / top-up / history / fulfillment scope creep
