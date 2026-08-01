---
status: proposed
created: 2026-07-31
decision: mobile-m3-1-purchase-api-contract
---

# Mobile M3.1 Purchase API Contract

## Decision

Ship OpenAPI **1.2.0** single-line purchase contract under `/api/v1` with:

- Sanitized `PackageRequirementField` on package detail
- Informational quote + signed fingerprint
- Idempotent wallet checkout
- Checkout status recovery by `Idempotency-Key`
- Owned minimal receipt

## Consequences

- Flutter M3.2 consumes this contract only after M3.1R review.
- Multi-line cart remains out of contract until a later milestone.
- `prices_visible=false` blocks purchasing without session teardown.

## Alternatives rejected

- Server-persisted cart
- Opaque authorizing quote tokens that skip checkout validation
- Relying only on web `cart_hash` reuse for mobile timeouts
- Broadening timeout windows instead of atomic attempt↔order linkage

## Safety corrections (M3.1R)

- Atomic purchase + attempt linkage; reconstructible receipts
- Safe stale recovery + status reconciliation
- Status-only stale orphans → `409 checkout_retry_required` (same key resubmit)
- Unified attempt-before-order lock order; indexed `orders.mobile_attempt_key_hash`
- Quote fingerprint ledger decimals (no `sprintf('%.2F', float)` bridge)
- Distinct-key intentional repurchase (mobile attempt hash in reuse context)
- Shared Idempotency-Key validation (128 max) on checkout and status
- Terminal attempt pruning at configured 72h retention (batched deletes)
- Opt-in MySQL concurrency harness under `tests/Concurrency/`
  (self-spawned cross-platform serve + fail-closed APP_KEY/fixture handshake)
- In-transaction rollback probe (`MobileCheckoutCommitGate`) for atomicity tests only
- Mobile order/debit totals use `PriceQuoteDTO` ledger decimals
