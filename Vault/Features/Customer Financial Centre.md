---
status: closed
created: 2026-07-29
closed: 2026-07-30
feature: customer-financial-centre
milestone: M6.8
---

# Customer Financial Centre

Canonical M6 feature record. Code is truth when docs conflict. See [[Wallet & Ledger]], [[Refunds & Settlements]], [[Orders & Checkout]], [[Customer Activity]].

## M6.8 — Closure Review (closed)

### Verdict

- **M6 can close.** Architecture, wallet kernel, workspaces, receipts, earnings and realtime form one coherent Financial Centre.
- **No code launch blocker** found in M6 surfaces. Manual Reverb (two-tab), Arabic mobile, and A4 print acceptance remain required before production launch.
- Pre-M6.7 audit notes that claimed “M6.7 not started / missing writers / no focus reconcile” are **obsolete** — code and this note’s M6.7 section are truth.

### Confirmed invariants (spot-checked vs code)

- All product posted money through `WalletLedger`; only intentional non-ledger balance write is `wallet:reconcile --repair`.
- Truth boundaries: balance snapshot / posted TX / TopupRequest / refund TX / Commission / commission_credit TX / PayoutRequest / Activity / notifications — no duplicate owners.
- Realtime taxonomy matches enum + `CustomerFinancialRealtimeScope`; payload = `reasons` + `schema_version` + `event_id` only.
- Routes: `/wallet`, transactions (+ WTX detail), topups (+ TUP detail), create top-up, refunds (+ WTX detail), earnings (`view_referrals`).
- Late credited commission clawback **shipped in M7.1** (obligation + `commission_reversal`); see [[M7 — Financial Risk and Admin Operations]]. Pending→failed unchanged.
- Decimal `(10,2)` wallet TX vs `(12,2)` orders/commissions — overflow at extreme totals; plan expansion before those limits, not M6 close.
- Unrelated full-suite failures (admin roles, custom-amount checkout assert, Arabic 2FA copy, settlements modal) are outside Financial Centre ownership.

### Manual before launch

- Two-tab Reverb: purchase, top-up, refund, commission, payout request, page-2 banners, offline/reconnect, print deferral, unread isolation.
- Arabic RTL 320–390 px + A4 print / Save as PDF.

### Not started

- M7, API/Sanctum, clawback implementation, BroadcastChannel, JS test harness, Git/deploy from this review.

### Shipped

- **2026-07-30 M6.8** — read-only closure review; Vault/SYSTEM_CONTEXT factual corrections only.

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

## M6.7 — Financial Realtime Synchronisation and Reconciliation (shipped)

### Durable contract

- One authenticated private channel: `App.Models.User.{id}`. Notifications, Activity and Financial listeners share it; Financial pages never create channels.
- Financial transport: `CustomerFinancialStateChanged` through `CustomerFinancialBroadcaster`.
- Payload: allowlisted `reasons[]`, `schema_version`, and opaque `event_id` only. No timestamp, amount, balance, wallet/user/source ID, reference, status, URL, metadata or customer data.
- Financial, Activity and notification/unread clients are separate semantic systems. A workflow may emit more than one system signal, but one never mutates another system’s client state.
- Workflow Actions own invalidation. `WalletLedger`, models, readers, presenters and Blade do not broadcast financial facts.
- Broadcaster defers inside transactions and catches delivery failures after commit. Rollback produces no financial success signal; Reverb failure cannot undo money.

### Frozen reason taxonomy

- `TransactionPosted` — a posted customer wallet transaction exists.
- `BalanceChanged` — wallet snapshot changed without a new transaction; reserved for reconcile repair.
- `CreditFacilityChanged` — credit availability/terms changed.
- `TopupStateChanged` — top-up workflow state changed.
- `RefundStateChanged` — refund workflow state changed.
- `CommissionStateChanged` — commission created, failed or credited.
- `PayoutRequestStateChanged` — payout request created or processed; no money movement.
- Removed `CommissionCredited`; credit now emits one event containing `TransactionPosted` + `CommissionStateChanged`.

### Writer catalogue

- Purchase → buyer `TransactionPosted`; new referral commissions → salesperson `CommissionStateChanged` only when at least one row was created.
- Wallet adjustment → target customer `TransactionPosted`; replay is silent.
- Credit facility update → target customer `CreditFacilityChanged` only when fields changed.
- Top-up submit/reject → owner `TopupStateChanged`; approve → one owner event with `TransactionPosted` + `TopupStateChanged`; replay is silent.
- Refund request/reject/dismiss → owner `RefundStateChanged`; approve → one owner event with `TransactionPosted` + `RefundStateChanged`; pending commission failures → deduplicated salesperson `CommissionStateChanged`.
- Payout batch → one event per affected salesperson per batch with `TransactionPosted` + `CommissionStateChanged`, not one per commission.
- Payout request create/process → salesperson `PayoutRequestStateChanged`; duplicate create/process is silent.
- Reconcile audit → no event; reconcile repair → owner `BalanceChanged` only when drift was repaired.
- Profit settlement → no customer event because it mutates the platform wallet.
- No top-up cancellation writer exists; M6.7 did not invent a cancellation workflow merely for signal symmetry.

### Surface routing

- Overview: transaction posted, balance, credit facility, top-up and refund. It ignores payout-request-only and commission-workflow-only changes.
- Ledger: transaction posted only.
- Top-up list/detail: top-up state only.
- Refund list/detail: refund state only.
- Earnings: commission and payout-request state only.
- Transaction detail: top-up/refund/commission source-context changes; immutable posted facts ignore generic transaction/balance events.
- Lifecycle reconciliation (`isReconcile`) is relevant to every mounted financial surface and reloads server truth.

### Client and page behaviour

- Separate Activity and Financial coalescers; Financial unions allowlisted reasons in one bounded 600 ms window.
- One global Echo listener per tab; init and reconnect binding are guarded against duplicate registration.
- Hidden tabs retain a bounded reason set and defer reads/announcements. Visibility, focus, online and Echo reconnect converge through a five-second lifecycle throttle.
- Cross-tab policy: accept one bounded refresh per active tab. No BroadcastChannel or leader election at current scale.
- Page 1 lists preserve filters/search/date inputs and perform one read per relevant coalesced burst.
- Page 2+ lists preserve visible rows, set one translated pending banner, use `skipRender()`, and perform zero list read until “Return to latest”; applying resets to page 1.
- Detail pages ignore irrelevant reasons. Transaction detail defers relevant updates during print and reconciles once after print.
- Global polite live region announces one translated summary per visible coalesced burst; no amount and no focus movement.

### Query and delivery budgets

- One server event per affected user per Action semantic burst; multi-reason events avoid duplicate frames.
- Overview: one `GetCustomerFinancialOverview` read for a relevant burst; payout request performs zero overview reads.
- Lists: one read on page 1; zero on page 2+ until apply.
- Details: zero reads for irrelevant reasons; at most one relevant/reconcile read.
- Earnings batch: one user event even when multiple commission rows are credited.
- Financial invalidation does not query Activity or notifications and does not alter unread/read state.

### Verification and manual requirements

- Automated: event payload/after-commit/rollback/failure isolation; writer replay and fan-out; reason routing; overview/list/detail/earnings relevance; page-2 zero-read; print deferral; notification isolation; M5/M6 regressions.
- Focused M6.7 + M5 Activity regression: **117 passed (406 assertions)** after final Activity-boundary and payload-minimisation corrections.
- Full Pest run: **997 passed / 8 failed** before the correction; the one M6-adjacent Activity failure was fixed and passed. The remaining seven reproduce independently in pre-existing admin role seeding, custom-amount checkout precision, Arabic-locale 2FA copy, and settlement modal assertions.
- Pint clean; frontend production build completed successfully.
- Manual Reverb acceptance remains required in a real two-tab browser session: purchase, top-up submit/approve/reject, refund request/approve/reject, commission create/credit, payout request, page 2+, offline/reconnect, print deferral, Arabic RTL and 320–390 px.

### Deferred scale improvements

- BroadcastChannel/leader election only after measured multi-tab load justifies it.
- Queued financial broadcasts only with explicit deduplication/observability design.
- A small JS test harness may be added when the project adopts one; M6.7 did not install a framework solely for coalescer tests.
- Top-up cancellation remains product workflow scope, not realtime scope.
- API/Sanctum deferred. M6.8 closure review completed 2026-07-30 (read-only).

### Shipped

- **2026-07-30 M6.7** — reason-set payload, Action-owned writers, replay/fan-out hardening, scoped Livewire registration, page-2 stability, lifecycle reconciliation, print deferral, accessibility/localisation and focused performance/security tests.

---

## M6.6 — Salesperson Earnings and Commission Clarity (shipped)

### Commission truth model

- **Commission** = earnings workflow truth (`pending` | `credited` | `failed`)
- **Pending** = calculated, not spendable
- **Eligible** = derived (hold days + completed fulfillment + not batched) — still not wallet money
- **Credited** = requires linked posted `commission_credit` WalletTransaction; amount must match; wallet owner = salesperson
- **Failed** = will not credit under current workflow (refund of pending)
- **Wallet.balance / availableToSpend** = spendable snapshot — never includes pending
- **PayoutBatch** = admin grouping that posts credits via `CreatePayoutBatch`
- **PayoutRequest** = request/workflow signal only (`pending`|`processed`); does **not** move money
- Activity / notifications = delivery only

### Terminology (EN / AR gist)

| Concept | Money moved? | Next actor |
|---|---|---|
| Pending / معلّقة | No | Staff/system or wait hold |
| Eligible / مؤهلة للإضافة | No | Staff (`CreatePayoutBatch`) |
| Credited / أُضيفت إلى المحفظة | Yes (posted TX) | None (or support if anomaly) |
| Failed / فاشلة | No | None |
| Payout request / طلب صرف | No | Staff review |
| Wallet available / المتاح في المحفظة | Snapshot only | Customer spend |

Avoid: “paid” for wallet credit; “available” for eligible; “balance” for pending; “total earnings” that silently includes failed.

### Formulas (server `LedgerMoney`)

- **Generated** = pending + credited + failed (labelled “Total generated”)
- **Credited total** = credited status sums only
- **Pending** = pending only
- **Failed** excluded from credited / earned
- **Wallet available** shown separately; never summed into pending

### Route / workspace

- Keep `/salesperson-dashboard` = business KPIs
- Add `/wallet/earnings` (`wallet.earnings.index`, `can:view_referrals`)
- Financial Centre nav **Earnings** tab only when `view_referrals`
- Overview lightweight salesperson link → earnings
- No duplicate dashboard; normal customers denied

### Read architecture

```
pages::frontend.wallet-earnings
→ GetSalespersonEarnings → SalespersonEarningsPageDTO / CommissionDTO
→ SalespersonEarningsPresenter → passive x-earnings.*
```

Shared eligibility: `SalespersonCommissionEligibility` (mirrors CreatePayoutBatch; decimal-safe).

### Public references

- No new `COM-*` / `PAY-*` in M6.6 (order number + `WTX-*` when credited is sufficient)
- Internal commission IDs not primary customer refs

### Payout request

- Floor: `RequestSalespersonPayout::MIN_ELIGIBLE_EXCLUSIVE` ($10 strict `>`) — **distinct** from admin batch min (`WebsiteSetting::getCommissionPayoutMinAmount()`, default $200)
- One pending request; lockForUpdate; no money movement; financial invalidate `PayoutRequestStateChanged`

### Refund / clawback

- Pending → failed on refund approve (unchanged)
- Credited + late refund → M7.1 clawback obligation + `commission_reversal`; Earnings shows reversed / waived-back / net / debt; Overview distinguishes clawback vs credit-facility debt
- M7.2.2 posted waiver credit (`commission_clawback_waiver`) in ledger/detail as Money in; debt clears through ordinary balance arithmetic
- M7.2.3 correction credit (`commission_reversal_correction`) distinct from waiver; dispute under-review is operational (no money on open)
- Anomalies → `needs_review` (admin notified); salesperson safe copy only
- See [[Refunds & Settlements]] + [[M7 — Financial Risk and Admin Operations]]

### Realtime

- Existing private user channel + `CustomerFinancialStateChanged`
- Reasons after M6.7: `CommissionStateChanged` (create / fail / credit / clawback / waiver / dispute / correction) and `PayoutRequestStateChanged`; reversal, waiver, and correction also emit `TransactionPosted` when money moves

### Magic Patterns

- Exploration only: https://www.magicpatterns.com/inspiration/3d22cd88-3b6f-41a8-be22-d300e7afd4e4 — **no generated code shipped**

### Deferred

- Commission clawback **historical exposure** — M7.2.4 shipped (report + review markers only; no SP self-service dispute; no historical collection)
- `COM-*` / `PAY-*` public refs if product needs them
- Dedicated salesperson order detail (order # is reference-only from earnings)
- Dedicated salesperson CLB detail route (optional)
- API/Sanctum

### Tests

`SalespersonEarnings*`, presenter unit; `CommissionClawbackTest`; SalespersonDashboard payout regressions; ReferralCommission / financial overview / TX detail / ledger filters green. Pint + `npm run build` OK.

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
- Salesperson earnings UI shipped in **M6.6** (`/wallet/earnings`) — see section above

### Deferred

- Commission clawback policy (still blocked without approved financial policy)
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
