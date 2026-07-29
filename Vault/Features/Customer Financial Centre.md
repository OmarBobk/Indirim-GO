---
status: shipped-topups
created: 2026-07-29
feature: customer-financial-centre
milestone: M6.3
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
- No Magic Patterns production code (exploration only)
- Web-first; no API/Sanctum in M6.x storefront surfaces

---

## M6.3 — Customer Top-up Workspace (shipped)

### Workflow truth

- `TopupRequest` = top-up workflow truth
- Pending `WalletTransaction` = mirror only (not posted money)
- Approval posts credit **once** via `WalletLedger`
- Posted top-ups appear in `/wallet/transactions`
- Activity may surface **rejected** top-ups as action-required
- Notifications remain delivery/unread truth
- Financial realtime: invalidation only (`CustomerFinancialStateChanged` → `customer-financial-invalidate`)

### Customer status contract

| Internal | EN label | AR label | Money moved? | Next actor | CTA |
|---|---|---|---|---|---|
| Pending | Under review | قيد المراجعة | No | Staff | Wait / view detail |
| Approved + posted TX | Credited to wallet | أُودع في المحفظة | Yes | Done | View in transactions |
| Approved without posted | Approved — credit pending (anomaly) | موافق عليه — الإيداع معلّق | No | Staff/support | Support hint |
| Rejected | Rejected / Needs your action | مرفوض / يحتاج إجراءً منك | No | Customer | Start corrected top-up |
| Cancelled | Cancelled / Closed | ملغى / مغلق | No | — | Start new (enum unused; cancel UX deferred) |

Draft = client-only form state (no DB row until submit).

### Routes / navigation

- `wallet.topups.index` → `/wallet/topups`
- `wallet.topups.show` → `/wallet/topups/{public_ref}` (route key `public_ref`)
- `wallet.topup` → `/wallet/topup` (create / retry)
- Financial Centre nav: **Overview | Transactions | Top-ups**
- Refunds tab not rendered (M6.4)

### Public reference

- Immutable `topup_requests.public_ref` = `TUP-` + 10 hex
- Unique index; collision retry; backfill migration for historical rows
- Not sequential; no user data; not idempotency key
- Detail URLs use `public_ref`; cross-user → 404

### List read architecture

```
pages::frontend.wallet-topups
→ GetCustomerTopupRequests
→ CustomerTopupPageDTO / CustomerTopupRequestDTO
→ CustomerTopupPresenter
→ passive x-wallet.topup-* / topups-*
```

Filters: All | Under review | Credited (approved+posted) | Needs your action (rejected) | Closed (cancelled). Search: `public_ref` prefix. Page size 20. Order: `updated_at DESC, id DESC`.

### Detail read architecture

```
pages::frontend.wallet-topup-detail
→ GetCustomerTopupDetail
→ CustomerTopupDetailDTO
→ CustomerTopupPresenter
→ passive header/timeline/proof/recovery
```

Timeline only from real evidence (submitted, proof presence, under review, reviewed/`approved_at`, credited/`posted_at`, rejected≈`updated_at`, cancelled). No fabricated review times from `updated_at` for approved path.

### Create / conversion / pending uniqueness

- Server validates method (active allowlist), amount, proof MIME/size
- TRY→USD locked at **submission** (`SubmitCustomerTopupRequest`); not recalculated at approval
- One pending top-up per user (transactional lock from M6.0.1); create UX shows pending banner + detail link; duplicate submit redirects to existing
- Success navigates to detail

### Rejection / retry

- Rejected remains immutable
- “Start corrected top-up” → `/wallet/topup?retry={TUP-…}` prefills method/amount; **never** reuses proof
- New `TopupRequest`; blocked while another pending exists
- Customer-safe reason = trimmed `note` (escaped in Blade); no admin IDs/internal notes

### Cancellation

- **Deferred** — `Cancelled` enum exists but no customer cancel flow in M6.3

### Proof security

- Private disk; authenticated owned route; MIME/extension/size; random path; no public URL; no path traversal; immutable after submit in M6.3

### Ledger / Overview / Activity / Realtime

- Credited detail links to ledger via `WalletTransactionsSearch` (`?search=WTX-…`) — no TX detail route (M6.5)
- Overview pending/rejected destinations use typed `WalletTopupDetail` / top-ups index
- Activity rejected → detail by `public_ref`
- List page 1 refresh / page 2+ banner; detail refreshes in place; same channel; no amounts in WS payload

### Indexes

- unique `public_ref`
- `(user_id, status, updated_at, id)` — `topup_requests_user_status_updated_idx`

### Query budgets

- List: 1 paginated TopupRequest + bounded relations + optional pending ref + WebsiteSetting prices
- Detail: 1 owned TopupRequest + payment method/proof/posted TX
- No Activity/notification/proof-content queries on list

### Historical compatibility

- Missing `public_ref` backfilled; list fallback label if empty
- Approved without posted = integrity anomaly (not “credited”)
- Missing method/proof/reason render safely

### Deferred

- M6.4 Refunds workspace
- M6.5 transaction detail / receipts / PDF
- Customer cancellation of pending top-ups
- Cursor pagination at scale

### Magic Patterns

- Short exploration only: https://www.magicpatterns.com/inspiration/0491dc6d-6eaf-4f85-a429-85d0bda82d6f — **no generated code shipped**

### Tests

`CustomerTopupWorkspace*`, `CustomerTopupDetailTest`, `CustomerTopupPublicRefTest`, `CustomerTopupSecurityTest`, `CustomerTopupPresenterTest`; create/admin/Activity/overview/ledger regressions green.

---

## M6.2 — Unified Customer Transaction Ledger (shipped)

See prior: `/wallet/transactions`, `WTX-*`, `posted_at`, Overview|Transactions (Top-ups added in M6.3).

## M6.1 — Customer Financial Overview (shipped)

Overview Action, pending ≤3, recent posted ≤5, financial Echo coalescer. Links into M6.3 detail/list.

## M6.0.1 — Wallet Mutation Kernel (shipped)

Kernel stamps `public_ref` + `posted_at` on posted/promoted wallet rows; pending top-up uniqueness.

## Shipped

- **2026-07-29 M6.0 / M6.0.1 / M6.1 / M6.2 / M6.3**

## Gotchas

- Do not label pending TX as money moved
- Credited filter requires posted TX — approved-without-post is anomaly
- Rejected `reviewedAt` approximated from `updated_at` (no dedicated `rejected_at` column)
- Cancellation not customer-facing yet
- Ledger link uses search until M6.5 detail exists

## Related

- [[Wallet & Ledger]]
- [[Refunds & Settlements]]
- [[Orders & Checkout]]
- [[Customer Activity]]
