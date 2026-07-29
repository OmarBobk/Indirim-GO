---
status: shipped-transaction-detail
created: 2026-07-29
feature: customer-financial-centre
milestone: M6.5
---

# Customer Financial Centre

Canonical M6 feature record. Code is truth when docs conflict. See [[Wallet & Ledger]], [[Refunds & Settlements]], [[Orders & Checkout]], [[Customer Activity]].

## Goal

Turn Wallet into the customer’s **Financial Control Centre** so a customer can answer: spendable now, what moved money, pending top-ups/refunds, rejection reasons, order linkage, next action, and auditability — without making Activity the financial ledger.

## Constraints

- Stack: Laravel 12, Livewire 4, Tailwind 4, Flux **FREE** only
- Financial writes: transactional, idempotent, `lockForUpdate`, `DB::afterCommit` side effects
- Activity = projection only; financial truth = `wallets.balance` + `wallet_transactions` (+ workflow source records)
- USD ledger currency only
- Posted arithmetic: `LedgerMoney` (BCMath scale 2) — never float
- No Magic Patterns production code (exploration only)
- Web-first; no API/Sanctum in M6.x storefront surfaces

---

## M6.5 — Transaction Details + Printable Receipts (shipped)

### Transaction-detail truth

- Detail reads **posted** owned customer `WalletTransaction` only
- Pending/rejected workflow rows and platform/settlement rows → **404**
- Lookup by immutable `public_ref` (`WTX-*`) — no raw ID fallback
- Ledger snapshots (`balance_before`/`balance_after`) are truth for historical balances — never recalculated from current wallet

### Route / reference

- `wallet.transactions.show` → `/wallet/transactions/{publicRef}`
- Auth + verified customer; wallet ownership enforced in `GetCustomerTransactionDetail`
- Malformed / foreign ref → 404

### Read architecture

```
pages::frontend.wallet-transaction-detail
→ GetCustomerTransactionDetail → CustomerTransactionDetailDTO
→ CustomerTransactionDetailPresenter → passive x-wallet.transaction-*
```

### Snapshot policy (C)

- Prefer `meta.receipt` stamped at post time by workflow Actions
- Safe owned live fallback for historical rows missing receipt keys
- `WalletLedger` stays mechanical — does **not** query source models
- `ReceiptSnapshot::VERSION = 1` (presenter/meta; not a DB column)
- No secrets/proof paths/admin notes in receipt snapshot

### Receipt scope

- Same detail page + `@media print` CSS (option A)
- Browser `window.print()` only — **no** Dompdf/Browsershot/server PDF
- Wording: wallet entry confirmation — not tax invoice / bank statement
- Print hides nav/header/controls; receipt remains visible; money LTR in RTL

### Typed destinations

- Primary list/overview href → transaction detail
- Source CTAs: order / top-up / refund / earnings (allowlisted via `FinancialDestinationType`)
- Credited top-up + posted refund `ledgerDestination` → `WalletTransactionDetail`

### Integrity

- Show confirmed ledger facts; hide unsafe source; “Related details unavailable”
- Log anomaly; no auto-repair from detail page

### Magic Patterns

- Exploration only: https://www.magicpatterns.com/inspiration/bf95c57a-426c-4db2-8218-9b2e8cd4ff1c — **no generated code shipped**

### Deferred (not M6.5)

- Server PDF, QR verification, signed URLs, public receipt portal, cryptographic hash
- M6.6 (not started)
- API/Sanctum

### Tests

`CustomerTransactionDetail*`, presenter unit; ledger/overview/refund/adjustment regressions green. Pint + `npm run build` OK. Manual A4/Arabic print still recommended in browser.

---

## M6.4 — Customer Refund Workspace (shipped)

### Truth model

- **No RefundRequest model** (insufficient need; avoid second source of truth)
- Workflow identity = refund `WalletTransaction` (pending|posted|rejected)
- Operational source = Fulfillment / OrderItem / Order (meta + morph)
- Posted money = posted refund TX only
- Activity = projection; notifications = delivery/unread

### Status contract

| Internal | EN | AR | Money moved? | Next actor | CTA |
|---|---|---|---|---|---|
| pending | Under review | قيد المراجعة | No | Staff | Wait / view detail |
| posted | Refunded to wallet | أُعيد إلى المحفظة | Yes | Done | View transaction detail |
| rejected + FF Failed | Needs your action | يحتاج إجراءً منك | No | Customer | Review on order |
| rejected + dismiss/recovered | Closed | مغلق | No | — | View order/support |
| unknown status | Credit pending (anomaly) | الإيداع معلّق | No | Support | Support hint |

### Public reference

- Reuse `WalletTransaction.public_ref` (`WTX-*`) assigned **at refund request** (not only on post)
- Same ref after promote; customer sees one reference for workflow + posted movement
- Historical pending/rejected backfilled (M6.4 migration)
- Mirrored into `fulfillment.meta.refund.public_ref` for query-free order-detail links

### Routes / navigation

- `wallet.refunds.index` → `/wallet/refunds`
- `wallet.refunds.show` → `/wallet/refunds/{public_ref}`
- Financial Centre nav: **Overview | Transactions | Top-ups | Refunds**

### Read architecture

```
pages::frontend.wallet-refunds
→ GetCustomerRefunds → CustomerRefundPageDTO / CustomerRefundDTO
→ CustomerRefundPresenter → passive x-wallet.refund*

pages::frontend.wallet-refund-detail
→ GetCustomerRefundDetail → CustomerRefundDetailDTO
→ CustomerRefundPresenter
```

Filters: All | Under review | Refunded | Needs your action | Closed. Search: WTX prefix + order_number. Page size 20.

### Integrations

- Overview pending/rejected → refund detail
- Activity rejected → refund detail
- Order recovery strip → View refund when `meta.refund.public_ref` present
- Posted → **transaction detail** (`WalletTransactionDetail`)
- Realtime: same financial invalidation channel

### Commission

- Pending commissions fail on refund post (unchanged)
- Credited commission late-refund **not clawed back** — documented debt

### Deferred

- Commission clawback policy
- Dedicated `rejected_at` column if needed

---

## M6.3 — Customer Top-up Workspace (shipped)

`/wallet/topups`, `TUP-*`, Overview|Transactions|Top-ups (Refunds in M6.4). Credited top-ups link to **transaction detail** (M6.5).

## M6.2 — Unified Customer Transaction Ledger (shipped)

`/wallet/transactions`, `WTX-*`, `posted_at`. Rows link to transaction detail (M6.5).

## M6.1 — Customer Financial Overview (shipped)

Overview Action, pending ≤3, recent posted ≤5, financial Echo coalescer. Recent rows → transaction detail (M6.5).

## M6.0.1 — Wallet Mutation Kernel (shipped)

## Shipped

- **2026-07-29 M6.0 / M6.0.1 / M6.1 / M6.2 / M6.3 / M6.4**
- **2026-07-30 M6.5** — transaction detail + printable HTML receipt

## Gotchas

- Rejected without Failed FF is Closed, not Needs action
- Historical TX may lack balance snapshots / receipt — show unavailable wording; never invent balances
- `meta` remains mutable after post; treat `meta.receipt` as write-once by convention
- Late credited commission remains uncleared on refund

## Related

- [[Wallet & Ledger]]
- [[Refunds & Settlements]]
- [[Orders & Checkout]]
- [[Customer Activity]]
