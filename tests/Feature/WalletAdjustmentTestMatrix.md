# Wallet Adjustment — Test Matrix

Scope: Admin Credit v1 (`AdjustWallet` + `WalletLedger` + Livewire page).

Legend: **I** = implement now · **D** = deferred / covered indirectly · **N/A** = not applicable to current API

---

## Authorization

| Case | Expected | Plan |
|------|----------|------|
| Admin with `adjust_wallets` | Page 200; action succeeds | **I** |
| Authenticated user without permission | Page forbidden/not found; action 403 | **I** |
| Salesperson (no `adjust_wallets`) | Denied | **I** |
| Customer | Denied | **I** |
| Guest | Redirect/unauthenticated | **I** |

## Validation

| Case | Expected | Plan |
|------|----------|------|
| Missing amount | Validation error; no balance change | **I** (Livewire + action) |
| Negative amount | Validation error | **I** |
| Zero amount | Validation error | **I** |
| Extremely large amount | Posts successfully (no max configured) | **I** (ledger/action sanity) |
| Invalid currency | N/A — currency comes from wallet, not input | **N/A** |
| Missing user | Validation / not found | **I** (Livewire) |
| Missing wallet | N/A — `Wallet::forUser` creates customer wallet | **N/A** |
| Missing idempotency key | Action validation / ledger `InvalidArgumentException` | **I** |
| Duplicate idempotency key (same payload) | Safe replay; single credit | **I** |
| Idempotency payload mismatch | `IdempotencyConflictException` | **I** |
| Missing reason | Allowed (optional) | **I** |
| Very long reason (>500) | Livewire validation error | **I** |

## Business Rules

| Case | Expected | Plan |
|------|----------|------|
| Customer wallet AdminCredit | Balance ↑; posted `adjustment` credit tx | **I** |
| Non-customer wallet | Rejected when reachable; `AdjustWallet` uses `Wallet::forUser` (always customer) | **I** (asserts customer wallet) · defensive type check covered by design |
| Wrong adjustment kind | Rejected when non-AdminCredit exists | **D** (enum has only AdminCredit) |
| Balance updated correctly | `previous + amount = new` | **I** |
| WalletTransaction created | type/direction/status/meta/idempotency | **I** |
| Activity logged | `wallet.adjusted` + narrative + properties | **I** |
| System event emitted | `wallet.adjustment.posted` financial | **I** |

## Concurrency

| Case | Expected | Plan |
|------|----------|------|
| Double-click (same key twice) | One credit; second returns same result | **I** |
| Two simultaneous requests | Same as idempotent replay under lock | **I** (sequential same-key; DB lock path) |
| Race during wallet update | Unique key + lock; mismatch throws | **I** (conflict path) |

## Security

| Case | Expected | Plan |
|------|----------|------|
| Unauthorized endpoint GET | Denied | **I** |
| Permission escalation (`manage_topups` only) | Denied | **I** |
| Tampered amount after review | Server re-validates amount on confirm | **I** |
| Modified user ID mid-flow | Credits currently selected user (still requires permission) | **I** |
| Manual HTTP bypass of Livewire | Route still gated; action enforces permission | **I** |
| Livewire request replay | Same idempotency key → no double credit | **I** |

## UI (Livewire)

| Case | Expected | Plan |
|------|----------|------|
| Search by name | Results include user | **I** |
| Search by email | Results include user | **I** |
| Search by username | Results include user | **I** |
| Search by phone | Results include user | **I** |
| Result preview calculation | Computed resulting balance | **I** |
| Success summary | Amount, new balance, tx id after confirm | **I** |
| Recent adjustments refresh | New row after successful post | **I** |

## Database assertions (bundled into happy-path / audit tests)

| Field | Plan |
|-------|------|
| Wallet balance | **I** |
| WalletTransaction row | **I** |
| Activity log | **I** |
| Meta (kind, actor, target, reason, ip) | **I** |
| previous_balance / new_balance | **I** |
| idempotency_key | **I** |

---

## Intentionally not tested

- Browser/Alpine preview JS internals
- True OS-level parallel threads (simulated via sequential idempotent calls + conflict)
- Framework middleware plumbing beyond route/action authorization
- Future adjustment kinds (Admin Debit, etc.)
