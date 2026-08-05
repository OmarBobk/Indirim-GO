---
status: accepted
created: 2026-07-31
updated: 2026-08-05
decision: mobile-m3-1-purchase-api-contract
---

# Mobile M3.1 Purchase API Contract

## Decision

Ship OpenAPI **1.2.0** single-line purchase contract under `/api/v1` with:

- Sanitized `PackageRequirementField` on package detail
- Informational quote + signed fingerprint
- Idempotent wallet checkout (`POST /checkout` + required `Idempotency-Key`)
- Checkout status recovery by `Idempotency-Key` (`GET /checkout/status`)
- Owned minimal receipt (`GET /orders/{order_number}`)
- Wallet summary (`GET /wallet/summary`)
- `prices_visible=false` → `409 purchasing_unavailable` without session teardown

**Accepted.** Laravel M3.1 merged to `staging` via PR #44
(`d23f961b1261a01f1adbd5eccfaae454ccfb8045`). Flutter M3.2 on mobile `main`
consumes this contract (PR #5 → `9e056f1d5d795c8ad9d9c4061a0fddac831bae6f`).
Full schemas live in `docs/api/v1/openapi.yaml` — link, do not copy here.

## Consequences

- Flutter M3.2 / M3.3 consume this contract only (no client-authoritative prices).
- Multi-line cart remains out of contract until a later milestone.
- `prices_visible=false` blocks purchasing without session teardown.
- Mobile M3 closed after M3.3 local acceptance; next architecture candidate only:
  **Mobile M4.0 — Orders and Fulfillment Status Architecture Audit** (see [[Mobile M3.0 — Purchasing Architecture]]).

## Alternatives rejected

- Server-persisted cart
- Opaque authorizing quote tokens that skip checkout validation
- Relying only on web `cart_hash` reuse for mobile timeouts
- Broadening timeout windows instead of atomic attempt↔order linkage

## Safety corrections (M3.1R — accepted into M3.1 ship)

- Atomic purchase + attempt linkage; reconstructible receipts
- Safe stale recovery + status reconciliation
- Status-only stale orphans → `409 checkout_retry_required` (same key resubmit)
- Unified attempt-before-order lock order; indexed `orders.mobile_attempt_key_hash`
- Quote fingerprint ledger decimals (no `sprintf('%.2F', float)` bridge)
- Distinct-key intentional repurchase (mobile attempt hash in reuse context)
- Shared Idempotency-Key validation (128 max) on checkout and status
- Terminal attempt pruning at configured 72h retention (batched deletes)
- Opt-in MySQL concurrency harness under `tests/Concurrency/`
  (self-spawned cross-platform serve + fail-closed APP_KEY/fixture handshake) —
  **accepted: all eight scenarios passed**
- In-transaction rollback probe (`MobileCheckoutCommitGate`) for atomicity tests only
- Mobile order/debit totals use `PriceQuoteDTO` ledger decimals

## Client pairing (M3.2)

- OpenAPI **1.2.0** from Laravel staging M3.1 commit above
- Pending recovery: per-customer secure store (key + optional completed `order_number`)
- See mobile `docs/architecture/m3.2-purchase-flow.md`
