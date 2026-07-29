---
status: shipped-refunds
created: 2026-07-29
feature: customer-financial-centre
milestone: M6.4
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
| posted | Refunded to wallet | أُعيد إلى المحفظة | Yes | Done | View in transactions |
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

### Timeline / timestamps

Evidence: meta `requested_at` / `rejected_at` / `approved_at`, column `posted_at`, `created_at`. No `updated_at` on wallet_transactions. Delivery-failed event shown without fabricated timestamp. Approval+post atomic → “Refunded to wallet” uses `posted_at`.

### Recovery / dismissal

- Actionable reject → order detail to re-request (domain already allows new TX after reject)
- No customer cancel/dismiss control
- Staff/system dismiss → Closed (`dismiss_reason`)
- Customer-safe reason: trimmed meta.note; strip system “Dismissed:” notes; length-bounded; Blade-escaped

### Integrations

- Overview pending/rejected → refund detail
- Activity rejected → refund detail (`WalletRefund` destination)
- Order recovery strip → View refund when `meta.refund.public_ref` present
- Posted → ledger search `?search=WTX-*`
- Realtime: same financial invalidation channel; request/dismiss now broadcast

### Commission

- Pending commissions fail on refund post (unchanged)
- Credited commission late-refund **not clawed back** — documented debt; not changed in M6.4

### Magic Patterns

- Exploration only: https://www.magicpatterns.com/inspiration/beb97eda-f69a-4677-91fb-f2eea0264132 — **no generated code shipped**

### Deferred

- M6.5 transaction detail / receipts / PDF
- Commission clawback policy
- Dedicated `rejected_at` column if ordering by rejection time becomes required

### Tests

`CustomerRefundWorkspace*`, `CustomerRefundDetailTest`; OrderItemRefund / admin refund / Activity / overview regressions green.

---

## M6.3 — Customer Top-up Workspace (shipped)

See prior: `/wallet/topups`, `TUP-*`, Overview|Transactions|Top-ups (Refunds added in M6.4).

## M6.2 — Unified Customer Transaction Ledger (shipped)

`/wallet/transactions`, `WTX-*`, `posted_at`.

## M6.1 — Customer Financial Overview (shipped)

Overview Action, pending ≤3, recent posted ≤5, financial Echo coalescer.

## M6.0.1 — Wallet Mutation Kernel (shipped)

## Shipped

- **2026-07-29 M6.0 / M6.0.1 / M6.1 / M6.2 / M6.3 / M6.4**

## Gotchas

- Rejected without Failed FF is Closed, not Needs action
- List ordering uses `COALESCE(posted_at, created_at)` — rejected rows sort by request time
- Partial refund = one fulfillment unit; do not present as full-order refund
- Late credited commission remains uncleared on refund

## Related

- [[Wallet & Ledger]]
- [[Refunds & Settlements]]
- [[Orders & Checkout]]
- [[Customer Activity]]
