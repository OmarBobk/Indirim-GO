---
status: accepted
created: 2026-07-31
updated: 2026-08-05
feature: mobile-m3-0-purchasing-architecture
---

# Mobile M3.0 — Purchasing Architecture

Architecture decisions for the first mobile purchasing milestone after M2.
**Mobile M3 is closed** after M3.1 (Laravel API), M3.2 (Flutter buy-now), and M3.3 (local Android integration).

## Accepted decisions

- **Cart model (M3):** buy-now / single-line purchase only (`items` max 1). No server cart. Multi-line Flutter-local cart deferred.
- **API shape (shipped OpenAPI 1.2.0):** `GET /wallet/summary`, `POST /checkout/quote`, `POST /checkout` (+ required `Idempotency-Key`), `GET /checkout/status`, `GET /orders/{order_number}`. Package detail includes sanitized `requirements`.
- **Quote fingerprint:** signed informational optimistic-concurrency guard; checkout always reprices.
- **prices_visible=false:** wallet summary remains; quote/checkout return `409 purchasing_unavailable` (not an auth rejection).
- **Idempotency:** required `Idempotency-Key` header; store SHA-256 hash scoped to customer; 72-hour retention + scheduled pruning.
- **Custom amounts + package requirements:** included in M3.1 / M3.2.
- **Flutter recovery (M3.2):** per-customer secure pending key + durable completed `order_number` anchor; receipt-before-key-clear; A→B isolation.
- **Orders history / top-ups / refunds / fulfillment tracking UI / production deploy:** deferred (separate decisions).

## Milestone status (closed)

| Slice | Status | Evidence |
|-------|--------|----------|
| M3.0 Architecture | Accepted | This note |
| M3.1 Laravel Purchase API | Accepted on `staging` | PR #44 → `d23f961b1261a01f1adbd5eccfaae454ccfb8045`; MySQL concurrency gate 8/8 |
| M3.2 Flutter Buy-Now | Accepted on mobile `main` | PR #5 → `9e056f1d5d795c8ad9d9c4061a0fddac831bae6f` (includes recovery fix); 197 CI tests |
| M3.3 Local Android integration | Accepted | Omar walkthrough; emulator `http://10.0.2.2:8000/api/v1` |

## Next architecture candidate (not started)

**Mobile M4.0 — Orders and Fulfillment Status Architecture Audit** — may evaluate customer-owned order history, receipt reopening, fulfillment status, and safe refresh. Do not design or implement until Omar accepts M3 closeout. Multi-line cart, wallet top-up, refunds, push notifications, and production deployment remain separate decisions.

## Related

- [[Mobile M3.1 — Laravel Purchase API]]
- [[Mobile M3.1 Purchase API Contract]]
- [[Mobile M3.2 — Flutter Buy-Now Purchasing Flow]]
- [[Mobile M3.3 — Local Purchase Integration]]
- [[Orders & Checkout]]
- [[Wallet & Ledger]]
