---
status: shipped-overview
created: 2026-07-29
feature: customer-financial-centre
milestone: M6.1
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
- No Magic Patterns production code; web-first; Flutter-ready boundaries only

---

## M6.1 — Customer Financial Overview (shipped)

### Overview purpose

`/wallet` answers in seconds: available to spend, prepaid, debt/credit, pending top-ups/refunds, blocking issues, next action. Recovery + glanceable truth — **not** the full ledger (M6.2).

### Final IA

1. Page header (Wallet / financial overview)
2. Balance hero — **Available to spend** primary; prepaid / debt / credit secondary
3. Primary actions — one yellow **Add funds**; contextual **Continue purchase**; text links Track top-ups / Track refunds
4. Pending financial summary — max **3** items; hide when empty
5. Recent posted transactions — max **5**; no filters/pagination
6. Secondary — salesperson earnings link (permission); loyalty text link (full tier card removed)

### Read architecture

```
Wallet Livewire (Volt)
→ GetCustomerFinancialOverview
→ FinancialOverviewDTO (+ balance / pending / recent DTOs)
→ CustomerFinancialPresenter
→ passive x-wallet.* components
```

Readers: `GetCustomerWalletBalanceSummary`, `CustomerPendingFinancialReader`, `CustomerRecentWalletTransactionsReader`. Explicit `User` + owned `Wallet`. No Eloquent in Blade. No Activity DTOs reused as wallet DTOs.

### Included / excluded

**Included:** available/prepaid/debt/credit (active only); pending+rejected top-ups; pending+actionable rejected refunds; outstanding debt recovery; posted purchase/topup/refund/adjustment/settlement/commission preview.

**Excluded:** full 100-row history; Activity feed/unread; loyalty tier card; timeline/system events on wallet; internal credit status language; admin notes beyond customer-safe reject reason; proof paths; filters/detail/receipts.

### Destinations (M6.1 limitations)

- Top-ups → `wallet.topup` (no dedicated top-ups workspace yet — M6.3)
- Refunds track → Activity `action_required` + orders category when available, else orders index (M6.4)
- No “View all transactions” CTA until M6.2 (omit dead link)

### Query budget (overview Action)

Measured (tinker, prepaid empty history): **10** queries —
wallet · pending top-ups · rejected top-ups · pending refunds · rejected refunds · recent posted `limit 5` · cache · permissions · roles · website_settings.

Credit + debt + 1 pending top-up + 1 pending refund + 5 recent: same shape (capped); **no** `limit 100`, **no** `notifications` table.

### Realtime

- Same private channel `App.Models.User.{id}`
- Separate JS coalescer: `customer-financial-invalidation.js` → Livewire `customer-financial-invalidate`
- Payload: reason / occurred_at / event_id only — no balances; server re-fetches overview
- Does **not** listen to Activity invalidation

### Security

- Auth user only; wallet/TX/top-up/refund ownership scoped
- No browser user ID for selection; forged invalidate ignored
- Presenter-ready arrays; no raw HTML; no mutation from overview

### UX decisions

- Magic Patterns exploration (Balance-first stack selected): https://www.magicpatterns.com/inspiration/4abe57e4-9e5d-49bb-aca2-27ddb50ca48a — concepts only, not production code
- Loyalty: reduced to footer link; rewards remain on `/loyalty`
- Empty pending: section hidden
- Money LTR inside RTL; EN/AR `financial_*` strings

### Deferred to M6.2+

- Unified transaction ledger, filters, pagination, detail routes, receipts/PDF
- Dedicated top-ups / refunds child workspaces (M6.3/M6.4)
- API/Sanctum

### Tests

- `CustomerFinancialOverviewTest`, `…PageTest`, `…RealtimeTest`, `…PerformanceTest`, `CustomerFinancialPresenterTest`
- Updated `CustomerWalletUiTest`, `CustomerWalletSystemEventsTest` for overview IA

---

## M6.0.1 — Wallet Mutation Kernel (shipped)

Kernel invariants unchanged by M6.1. See [[Wallet & Ledger]].

### Deferred findings (still open)

- decimal(10,2) vs (12,2) schema align
- Public refs / detail routes / receipts (M6.2+)
- True concurrent overlapping DB tests limited on SQLite
- Settlement orphan edge if Settlement row created before failed post (pre-existing)
- Credited commissions not reversed on late refund (product decision)

## Shipped

- **2026-07-29 M6.0:** Architecture discovery
- **2026-07-29 M6.0.1:** WalletLedger kernel hardening
- **2026-07-29 M6.1:** Customer Financial Overview read-model + `/wallet` UI + financial Echo coalescer

## Gotchas

- `wallet:reconcile` without `--repair` no longer mutates
- Overview pending “View all” routes to safest existing child (top-up / Activity / orders) — not inventing M6.3/M6.4 pages
- Debt appears both in balance hero and pending recovery item when > 0
- Credit fields shown only when facility **active** (suspended → prepaid-only available)

## Related

- [[Wallet & Ledger]]
- [[Refunds & Settlements]]
- [[Orders & Checkout]]
- [[Customer Activity]]
