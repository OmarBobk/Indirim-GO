---
status: accepted
created: 2026-07-31
updated: 2026-07-31
feature: mobile-m3-0-purchasing-architecture
---

# Mobile M3.0 — Purchasing Architecture

Architecture decisions for the first mobile purchasing milestone after M2.

## Accepted decisions

- **Cart model (M3):** buy-now / single-line purchase only (`items` max 1). No server cart. Multi-line Flutter-local cart deferred.
- **API shape:** `POST /checkout/quote` then `POST /checkout`, plus `GET /wallet/summary`, `GET /checkout/status`, `GET /orders/{order_number}`.
- **Quote fingerprint:** signed informational optimistic-concurrency guard; checkout always reprices.
- **prices_visible=false:** wallet summary remains; quote/checkout return `409 purchasing_unavailable` (not an auth rejection).
- **Idempotency:** required `Idempotency-Key` header; store SHA-256 hash scoped to customer; 72-hour retention.
- **Custom amounts + package requirements:** included in M3.1.
- **Orders history / top-ups / refunds / fulfillment tracking UI:** deferred.

## Related

- [[Mobile M3.1 — Laravel Purchase API]]
- [[Mobile M3.1 Purchase API Contract]]
- [[Orders & Checkout]]
- [[Wallet & Ledger]]
