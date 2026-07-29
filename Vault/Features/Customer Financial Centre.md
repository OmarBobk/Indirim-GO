---
status: shipped-kernel
created: 2026-07-29
feature: customer-financial-centre
milestone: M6.0.1
---

# Customer Financial Centre

Canonical M6 feature record. Code is truth when docs conflict. See [[Wallet & Ledger]], [[Refunds & Settlements]], [[Orders & Checkout]], [[Customer Activity]].

## Goal

Turn Wallet into the customer’s **Financial Control Centre** so a customer can answer: spendable now, what moved money, pending top-ups/refunds, rejection reasons, order linkage, next action, and auditability — without making Activity the financial ledger.

## Constraints

- Stack: Laravel 12, Livewire 4, Tailwind 4, Flux **FREE** only
- Financial writes: transactional, idempotent, `lockForUpdate`, `DB::afterCommit` side effects
- Activity = projection only; financial truth = `wallets.balance` + `wallet_transactions` (+ workflow source records)
- USD ledger currency only (display may convert TRY entry amounts)
- Posted arithmetic: `LedgerMoney` (BCMath scale 2) — never float
- No Magic Patterns; web-first; Flutter-ready boundaries only

## Non-goals (M6.0.1)

- Wallet UI redesign / M6.1 Overview
- Transaction routes, receipts, public refs migration
- Sanctum / API
- Double-entry accounting
- Git commit/PR

---

## M6.0.1 — Wallet Mutation Kernel (shipped)

### Final WalletLedger API

- `post(Wallet|WalletPosting, …)` — `WalletPosting` preferred; named args kept for BC
- `apply(WalletPosting)` — core engine
- `postCredit(...)` / `postDebit(...)` — convenience
- Result: `WalletAdjustmentResult` (`transaction`, `wallet`, `previousBalance`/`newBalance`, `wasReplayed`, `wasPromoted`)
- Supports **create posted** and **promote pending → posted**

### Invariants

1. Every product posted credit/debit goes through `WalletLedger`
2. Amounts are decimal strings via `App\Support\LedgerMoney` (scale 2, max `99999999.99`)
3. Debit floor = `Wallet::minimumAllowedBalance()` (credit facility) unless caller overrides (platform settle uses `0.00`)
4. Idempotency key required; DB unique is authoritative; conflict → `IdempotencyConflictException`
5. Snapshots in meta: `balance_before`/`balance_after` (+ legacy `previous_balance`/`new_balance`) + `ledger_kernel=m6.0.1`
6. Posted TX immutable for mechanical fields (model guard); meta append allowed
7. Side effects after commit; broadcast failure never rolls back money

### Migrated paths

| Path | Status |
|---|---|
| `AdjustWallet` | Kernel + financial invalidation on non-replay |
| `PayOrderWithWallet` | Migrated debit via kernel |
| `ApproveTopupRequest` | Promote pending via kernel; key `topup:{id}` |
| `ApproveRefundRequest` | Promote pending via kernel |
| `CreatePayoutBatch` | Migrated credit via kernel |
| `ProfitSettleCommand` | Migrated platform credit via kernel |
| `WalletReconcile` | **Audit-only default**; `--repair` = audited **snapshot** set (policy C) |

### Excluded / intentional non-kernel writes

- Test fixtures / `reset-data.php` direct balance sets
- `wallet:reconcile --repair` snapshot (documented; ledger post cannot close sum-vs-balance drift)
- Pending TX create (no balance change): `CreateTopupRequestAction`, `RefundOrderItem`

### Lock order

**Caller:** workflow source → wallet. **Kernel:** wallet → TX by idempotency / pending. Kernel does not lock orders/topups/commissions.

### Idempotency formats

- `purchase:order:{id}`
- `topup:{topup_request_id}`
- `refund:fulfillment|order_item|order:{id}`
- `commission_credit:{commission_id}`
- `adjustment:{caller key}`
- `settlement:{settlement_id}`

### Pending top-up uniqueness

Transactional: `lockForUpdate` on wallet + pending top-up rows inside `SubmitCustomerTopupRequest` before create. No DB partial unique index (cross-DB). Retry after resolve still allowed.

### Reconciliation policy (decided)

- **Default = audit-only** (no mutation)
- **`--repair` = Policy C** audited snapshot: set `balance` = Σ posted TXs under lock + activity audit
- Policy B (compensating TX) **rejected**: posting changes balance and Σ by the same amount → drift unchanged

### Financial invalidation

- Event: `CustomerFinancialStateChanged` on private user channel
- Reasons: `balance_changed`, `topup_state_changed`, `refund_state_changed`, `commission_credited`
- Payload: reason + occurred_at + event_id only (no balance/amount)
- Broadcaster: `CustomerFinancialBroadcaster` (afterCommit, failure isolated)
- Client wiring deferred to **M6.7**

### Activity invalidation (gaps closed)

| Path | Activity | Financial |
|---|---|---|
| Top-up submit | TopupStateChanged | TopupStateChanged |
| Top-up approve (non-replay) | TopupStateChanged | Balance + Topup |
| Top-up reject | TopupStateChanged | TopupStateChanged |
| Refund request | RefundStateChanged (existing) | — |
| Refund approve (non-replay) | RefundStateChanged | Balance + Refund |
| Refund reject | RefundStateChanged | RefundStateChanged |
| Purchase | OrderPaid (existing) | BalanceChanged |
| Commission credit | — (salesperson notify) | Balance + CommissionCredited |
| Adjustment | — (no customer notify) | BalanceChanged |

### Tests added

- `tests/Feature/WalletLedgerKernelTest.php`
- `tests/Feature/PostedWalletTransactionImmutabilityTest.php`
- `tests/Feature/CustomerFinancialStateChangedTest.php`
- `tests/Unit/LedgerMoneyTest.php`
- Updated `WalletReconcileCommandTest.php`

### Deferred findings (still open)

- decimal(10,2) vs (12,2) schema align
- Public refs / detail routes / receipts (M6.2+)
- Wallet UX pending+posted mix (M6.1/M6.2)
- True concurrent overlapping DB tests limited on SQLite
- Settlement orphan edge if Settlement row created before failed post (pre-existing)
- Credited commissions not reversed on late refund (product decision)

---

## Target IA (M6.1+)

Child routes under Wallet: Overview / Transactions / Top-ups / Refunds; Earnings stay on salesperson dashboard. Do not start until Omar gates M6.1.

## Shipped

- **2026-07-29 M6.0:** Architecture discovery
- **2026-07-29 M6.0.1:** WalletLedger kernel hardening; all product posted paths migrated; reconcile audit-only; financial invalidation event; Activity gaps closed for top-up/refund approve/reject

## Gotchas

- `wallet:reconcile` without `--repair` no longer mutates (breaking vs old default mutate)
- Compensating ledger TX cannot fix reconcile drift — use snapshot `--repair`
- Promote path uses `forceFill` for status/idempotency/meta only
- Credit facility floor lives on wallet helpers; kernel derives unless overridden

## Related

- [[Wallet & Ledger]]
- [[Refunds & Settlements]]
- [[Orders & Checkout]]
- [[Customer Activity]]
