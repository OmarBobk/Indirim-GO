---
status: shipped-m7.2.3
created: 2026-07-30
feature: m7-financial-risk-admin-ops
milestone: M7.2.3-disputes-corrections
owner: Omar
type: policy-architecture
---

# M7 — Financial Risk and Admin Operations

Track B. Canonical home for commission clawback policy and later admin financial-risk tooling.

**M7.0 approved 2026-07-30. M7.1 kernel shipped 2026-07-31. M7.2.0 architecture drafted 2026-08-01. M7.2.1–M7.2.4 shipped 2026-08-01. Track B closed.**

Related: [[Refunds & Settlements]], [[Wallet & Ledger]], [[Customer Financial Centre]], [[Orders & Checkout]], [[Future Roadmap - Automation and Growth]]

---

## M7.2.0 — Admin Operations Architecture (draft — not shipped)

### Executive verdict

M7.1 automated clawback works for valid cases but **has no operational recovery surface**. `needs_review` and exhausted-job quarantine notify admins without an inbox, retry Action, waiver, dispute, or correction path. Generic `/wallet-adjustments` must **not** become the escape hatch.

Ship admin ops in four thin milestones after Omar decides the decision sheet below.

### M7.1 lifecycle (audited)

| Transition | Actor | Mechanism | Money | Recovery today |
|---|---|---|---|---|
| Refund posted → obligation `pending` (or `needs_review` at create) | System via `ApproveRefundRequest` | Same DB TX as customer refund | None | None (inbox missing) |
| `pending` → `processing` | `ProcessCommissionClawback` | Job after commit; lock obligation | None | Stale `processing` can stick |
| `processing` → `posted` | Action + `WalletLedger::postCommissionReversal` | Debit `commission_reversal` | Debit | Idempotent replay only |
| Any → `needs_review` | Action quarantine / create-time anomaly / job `failed()` | Integrity or `job_exhausted` | None | **Admin notify only — no retry UI** |
| Enum `failed` | Rarely written | Status exists | — | **Operationally unused** (exhaustion → needs_review) |

**Authoritative records:** Commission = earnings; CommissionClawback = obligation; WalletTransaction = money; SystemEvent/Activity = projection.

**No recovery path today for:** needs_review integrity, job_exhausted, stale processing, posted-but-should-forgive, posted-in-error, pre-M7.1 historical exposure.

### Queue / status contract

**Stored workflow statuses (keep small; evolve carefully):**

| Status | Meaning | M7.2 action |
|---|---|---|
| `pending` | Durable; not posted | Auto job or admin retry |
| `processing` | Reserved by worker | Stale recovery / sweeper |
| `posted` | Reversal linked | Waiver / correction / dispute overlay |
| `needs_review` | Quarantine | Classify → retry or correct source |
| `failed` | Reserved for retryable terminal tech (prefer reuse of needs_review + taxonomy) | Retry if code allows |
| `waived` (**add M7.2.2**) | Terminal forgiveness (esp. unposted full) | No further collection |
| `disputed` (**optional M7.2.3**) | Or keep dispute on decision table only | Pause unposted only |

**Derived filters (do not store as status):** debt outstanding / recovered; retryable; historical exposure; waiver/correction totals from posted credits.

### Workspace recommendation

**Dedicated section (Option A), future-compatible with a Financial Exceptions Centre:**

- `/admin/commission-clawbacks` — inbox
- `/admin/commission-clawbacks/{CLB-*}` — detail
- Sidebar: under Financials next to Commissions / Payout requests
- Permission-gated; backend entry 404 when lacking backend permission; Livewire follow existing component auth pattern
- Deep-links from commissions, refunds, system events by `CLB-*` / related WTX
- **Do not** bury under `/admin/commissions` list (different grain)
- **Do not** build unified Financial Exceptions in M7.2.1 — leave naming/nav room only

No salesperson dedicated CLB detail route in M7.2.1 (Earnings + WTX + CLB ref in copy is enough).

### Inbox (M7.2.1)

Default order: action-required (`needs_review`, retryable `failed`/`job_exhausted`, stale `processing`) → newest `created_at`/`id`. Page size 20.

Columns: CLB, salesperson, amount, status, failure category, debt derived, refund WTX, reversal WTX, order #, policy version, timestamps, retry eligible.

Filters: status, needs_review, retryable, debt outstanding/recovered, policy version, date, salesperson, search `CLB-*` / `WTX-*` / order number.

No customer PII beyond existing admin order conventions; no raw meta search.

### Detail (M7.2.1+)

Sections: required action; financial facts; source context; timeline; integrity checklist; actions (Retry / Waive / Dispute / Correct — gated by milestone + permission).

Safe failure codes only in UI; developer context stays in logs.

### Retry policy (M7.2.1)

- Action: `RetryCommissionClawback` → authorize → lock → eligibility → set `pending` → after-commit dispatch `ProcessCommissionClawbackJob`
- Revalidates all facts; never browser amount; never second reversal; posted = no-op
- **Retryable codes:** `job_exhausted`, deadlock/transient equivalents, stale_processing
- **Non-retryable until source fixed:** wrong_wallet, missing/invalid credit, amount mismatches, fulfillment/refund mismatch, reversal_conflict, commission_not_credited, policy_not_applicable, unsupported_historical
- **Stale processing sweeper:** recommended (e.g. `processing` + `attempted_at` older than N minutes → `pending` or `needs_review` + `stale_processing`)

### Waiver policy (M7.2.2)

| Case | Money | Outcome |
|---|---|---|
| Unposted **full** waiver | No WTX | Status `waived`; commission stays credited |
| Unposted **partial** | Deferred in v1 (complexity) | — |
| Posted full/partial waiver | Credit `commission_clawback_waiver` via WalletLedger | Never mutate reversal; cumulative ≤ posted reversal |

Waiver may return money even if debt already recovered by later credits (wallet can go further positive). Do **not** silently cap at current negative balance.

### Dispute policy (M7.2.3)

- Admin opens on behalf of salesperson only in v1 (no SP self-serve dispute)
- Does **not** undo posted reversal automatically
- May **pause** unposted processing only
- Accept → delegate to Waiver or Correction Action; Reject → no money change
- Payout still blocked while clawback debt remains
- Not a general ticketing system

### Erroneous clawback correction (M7.2.3)

- Distinct from waiver: **invalid/excessive** clawback, not forgiveness of a valid one
- Credit type: `commission_reversal_correction`
- Never delete/mutate `commission_reversal`
- Cap: cumulative waiver + correction credits ≤ posted reversal (unless separate compensation Action later)
- Higher permission than retry

### Arithmetic

```
net_clawback_collected = posted_reversal − posted_waiver_credits − posted_correction_credits
remaining_to_post (unposted) = obligation_amount − waived_before_post   # v1: waived_before_post is 0 or full
```

LedgerMoney / BCMath scale 2; server-derived remaining only.

### Historical exposure (M7.2.4) — shipped

Read-only report: credited commission + proven posted refund + no clawback + no posted `commission_reversal` + outside automatic policy (`refund.posted_at` < `billing.commission_clawback.effective_at`, or all such gaps when `effective_at` unset).

**Confidence:** `confirmed` (valid credit link + join-proven refund↔fulfillment) | `incomplete` (missing/invalid credit).

**Exposure amount:** full credited commission amount (one commission per fulfillment; LedgerMoney; no proportional math).

**Review markers:** table `historical_commission_exposure_reviews` (unique commission+refund). Outcomes: `platform_absorbed`, `not_actionable`, `insufficient_data`, `duplicate_or_invalid`, `deferred_review`. **Not financial truth** — no WalletLedger, no clawback, no Commission/refund mutation.

**Permission:** `view_historical_commission_exposure` (view + mark review). Admin by seeder. Not via `adjust_wallets` / process / settlements.

**Route:** `/admin/commission-clawbacks/historical-exposure` (static before `{clawback}`). Page-local summary only; no global sidebar historical count. CSV export **deferred**.

Manual historical collection remains **intentionally rejected/deferred** — not available.

### Permissions (recommended)

| Permission | Purpose |
|---|---|
| `view_commission_clawbacks` | Inbox + detail |
| `process_commission_clawbacks` | Retry / stale recovery |
| `waive_commission_clawbacks` | Waiver |
| `correct_commission_clawbacks` | Erroneous correction |
| `manage_commission_clawback_disputes` | Open/resolve dispute |
| `view_historical_commission_exposure` | Historical report |

Default: **admin all**; supervisor **none** unless Omar grants; salesperson/customer **none** on backend. Never grant via `adjust_wallets`.

### Ledger taxonomy (recommended)

**Option A — distinct types:**

- `commission_reversal` (exists, debit)
- `commission_clawback_waiver` (credit)
- `commission_reversal_correction` (credit)

Reject generic `adjustment` for these meanings.

### Decision records (recommended)

Immutable **`commission_clawback_decisions`** table (typed: waiver | correction | dispute_opened | dispute_resolved | retry_authorized | historical_review) with `idempotency_key`, optional `related_wallet_transaction_id`, reason_code, actor_id, amount nullable.

Prefer deriving money totals from WalletTransactions; decisions store workflow authority + audit timeline. Avoid untyped JSON logs.

### Exception counts

Add permission-aware keys to `GetAdminExceptionCounts`: `clawback_needs_review`, optionally `clawback_retryable` / `clawback_stale_processing`. Indexed status counts; no dashboard historical scan. Sidebar badge under Financials when permitted.

### Realtime

Reuse `TransactionPosted` + `CommissionStateChanged` for money outcomes. Dispute-only → `CommissionStateChanged` (Earnings/inbox). Optional admin ops broadcast pattern like payout-requested — only if existing AdminOpsBroadcaster conventions fit. No amounts/IDs in private-user payloads.

### Milestone split

| Milestone | Scope | Money mutations | Stop |
|---|---|---|---|
| **M7.2.1** | Inbox + detail (read) + Retry + stale sweeper + counts + permissions view/process | None beyond re-running existing Process path | No waiver/dispute/correction/historical |
| **M7.2.2** | Full unposted waiver + posted full/partial waiver + Earnings/ledger mapping | `commission_clawback_waiver` | No dispute/correction/historical |
| **M7.2.3** | Admin dispute + correction Action + SP notifications | `commission_reversal_correction` | No historical debit |
| **M7.2.4** | Historical exposure report + review markers; Track B closure review | None | No auto/manual bulk historical debit |

### Omar decisions (recommended)

1. **Workspace:** Dedicated `/admin/commission-clawbacks` (A) — not unified exceptions yet  
2. **View:** `view_commission_clawbacks` — admin only by default  
3. **Retry:** `process_commission_clawbacks`  
4. **Waive:** `waive_commission_clawbacks` (stricter)  
5. **Correct:** `correct_commission_clawbacks` (strictest money)  
6. **Partial unposted waiver:** No in v1  
7. **Unposted full waiver:** No wallet TX  
8. **Posted waiver:** Dedicated `commission_clawback_waiver` credit  
9. **Waiver vs correction types:** Separate  
10. **Admin open dispute:** Yes  
11. **Dispute pauses unposted:** Yes  
12. **SP initiate dispute v1:** No  
13. **Historical:** Report-only  
14. **Manual historical clawback:** Not in M7.2; defer  
15. **Retryable:** transient + job_exhausted + stale; not integrity  
16. **Stale sweeper:** Yes  
17. **Sidebar/dashboard counts:** Yes for needs_review (+ retryable if cheap)  
18. **SP CLB detail route:** Not in M7.2.1  

### Explicit non-goals (M7.2.0)

- No production code / migrations / admin pages  
- No retry/waiver/dispute/correction Actions  
- No M7.2.1 start  
- No Git/deploy  

---

## M7.2.1 — Admin Clawback Inbox and Retry (shipped)

### Goal

Authorized admins can see clawback obligations, prioritize action-required cases, inspect one CLB safely, retry only eligible operational failures, recover stale processing, and see permission-aware exception counts — without new money-moving TX types.

### Shipped — 2026-08-01

- **Routes:** `GET /admin/commission-clawbacks` (`admin.commission-clawbacks.index`), `GET /admin/commission-clawbacks/{CLB-*}` (`admin.commission-clawbacks.show`)
- **Permissions:** `view_commission_clawbacks`, `process_commission_clawbacks` (admin both by seeder; no supervisor/SP/customer default)
- **Read spine:** Livewire inbox/detail → `GetAdminCommissionClawbacks` / `GetAdminCommissionClawbackDetail` → DTOs → `AdminCommissionClawbackPresenter` → Blade
- **Retry:** `CommissionClawbackRetryEligibility` + `RetryCommissionClawback` → pending → after-commit `ProcessCommissionClawbackJob` only (no amount/wallet/SP from browser; no WalletLedger in Action)
- **Retryable:** `job_exhausted`, `stale_processing`, allowlisted transient codes, pending redispatch; integrity codes denied
- **Stale sweeper:** `commission-clawbacks:sweep-stale` (`--limit`, `--dry-run`, `--clawback=CLB-*`); schedule every 5 minutes; orphaned reversal → `needs_review`/`orphaned_reversal` (no auto-link)
- **Config:** `billing.commission_clawback.processing_stale_minutes` (default 30)
- **Counts:** `GetAdminExceptionCounts` adds `clawback_needs_review`, `clawback_retryable`, `clawback_stale_processing`, `clawback_action_required_total` (permission-aware; zero query if unauthorized)
- **Nav:** Financials sidebar + `ClawbackIndicator` badge on action-required total
- **Audit/realtime:** system events `commission.clawback.retry_requested|retry_denied|stale_recovered`; `AdminOpsBroadcaster` `clawback-retry-queued` / `clawback-stale-recovered`; page refresh remains source of truth
- **Notifications:** needs-review recipients = `process_commission_clawbacks` holders; destination = admin CLB detail
- **Indexes:** `cc_status_attempted_idx`, `cc_status_failure_idx`; columns `last_retry_at`, `retry_count`
- **Tests:** `AdminCommissionClawbackInboxTest`, `RetryCommissionClawbackTest`, `SweepStaleCommissionClawbacksTest` + M7.1 suite green

### Key files

- `app/Livewire/Admin/CommissionClawbacksIndex.php`, `CommissionClawbackShow.php`
- `app/Actions/Commissions/GetAdminCommissionClawbacks.php`, `GetAdminCommissionClawbackDetail.php`, `RetryCommissionClawback.php`
- `app/Support/Commissions/CommissionClawbackRetryEligibility.php`, `CommissionClawbackFailurePresentation.php`, `CommissionClawbackActionRequiredQuery.php`
- `app/Console/Commands/Commissions/SweepStaleCommissionClawbacksCommand.php`
- `app/Livewire/Sidebar/ClawbackIndicator.php`

### Gotchas

- Viewing does **not** imply retry; Action re-checks `process_commission_clawbacks`
- Backend denial: hidden entry + permission gate; Livewire/actions use 404; salesperson without backend access → 404
- Unique job identity collapses duplicate dispatches for same CLB
- `failed` status still rarely written; exhaustion stays `needs_review` + `job_exhausted`
- Pending is retryable for redispatch but **not** action-required badge/count
- No dispute / correction / historical / SP CLB detail / bulk retry / bulk waiver

### Deferred

- **M7.2.2** ~~waiver~~ → **shipped** (see below)
- **M7.2.3** dispute + erroneous-reversal correction
- **M7.2.4** ~~historical exposure report-only~~ → **shipped** (see below)

---

## M7.2.2 — Commission Clawback Waivers (shipped)

### Goal

Authorized admins can forgive valid clawbacks immutably: unposted full waiver (no money TX) or posted full/partial waiver via dedicated `commission_clawback_waiver` credit — without disputes, corrections, generic adjustments, or historical collection.

### Shipped — 2026-08-01

- **Permission:** `waive_commission_clawbacks` (admin by seeder; not implied by view/process; not via `adjust_wallets`)
- **Decision model:** `commission_clawback_decisions` + `CommissionClawbackDecision` (`CLD-*`); type `waiver` (correction/dispute cases reserved, unused)
- **Statuses:** clawback `waived` added; partial waiver keeps stored `posted` (derived from posted waiver credits)
- **TX type:** `WalletTransactionType::CommissionClawbackWaiver` credit via `WalletLedger::postCredit` (original `commission_reversal` immutable)
- **Arithmetic:** `remaining_waivable = posted_reversal − posted_waiver_credits (− reserved correction path = 0)`; never capped by current negative balance
- **Eligibility:** `CommissionClawbackWaiverEligibility` → modes `unposted_full` | `posted_full` | `posted_partial`
- **Action:** `WaiveCommissionClawback` — lock clawback → decisions → (posted) reversal WTX → create decision → credit if posted → mark `waived` when remaining = 0
- **Idempotency:** decision `idempotency_key` unique; wallet key `commission_clawback_waiver:{decision_id}`; Action replay returns existing
- **Processor/retry/sweeper:** `waived` is final (retry denied; Process no-op; sweeper only inspects `processing`)
- **Admin UI:** detail Waive form (reason allowlist, amount if posted, internal note, confirm); inbox filters `waived` / `partially_waived` + badges via `withExists` (no N+1); no list one-click / bulk
- **Reason taxonomy:** `commercial_goodwill`, `operational_exception`, `management_decision`, `salesperson_relief`, `other_approved`
- **Earnings:** waived_back total; row fully/partially waived; net = gross − reversals + waiver credits
- **Wallet/debt:** ordinary balance arithmetic; payout unlocks when debt clears (no special unlock)
- **Ledger/detail/receipt:** Money in · Commission clawback waiver; safe explanation; snapshot refs only (no admin note)
- **Notify/realtime:** `CommissionClawbackWaiverApprovedNotification` (SP); Activity informational; after-commit `TransactionPosted` (posted only) + `CommissionStateChanged`; `AdminOpsBroadcaster` `clawback-waived`
- **Audit:** system events `commission.clawback.waived` / `commission.clawback.waiver_posted`; activity_log may hold admin note
- **Tests:** `CommissionClawbackWaiverTest` + M7.1 / M7.2.1 suites green

### Lock order

1. `CommissionClawback`
2. Existing decisions (serialize remaining math)
3. Reversal `WalletTransaction` (posted path)
4. Waiver decision row create
5. Salesperson `Wallet` (via WalletLedger)
6. Ledger posting

### Gotchas

- Unposted path ignores browser amount and always full-waives obligation amount (no partial unposted)
- Matching reversal without linked `reversal_wallet_transaction_id` → deny + quarantine `orphaned_reversal` (no invent link)
- Active non-stale `processing` denies waiver until stale recovery/retry path
- Waiver may credit after debt already recovered (wallet can go further positive)
- View/process permissions do **not** imply waive
- Direct waive blocked while an active dispute is open (resolve-as-waiver uses `allowWhileDisputed`)
- Net zero after **any** correction credits stays `posted` (fully corrected) — never labeled `waived`

### Deferred

- **M7.2.3** ~~dispute + correction~~ → **shipped** (see below)
- **M7.2.4** ~~historical exposure report-only~~ → **shipped** (see below)

---

## M7.2.3 — Disputes and Erroneous Reversal Corrections (shipped)

### Goal

Admin-only dispute lifecycle (no money on open) plus dedicated erroneous-reversal correction credits, sharing the cumulative recovery cap with waivers — without support tickets, SP self-service, or historical collection.

### Shipped — 2026-08-01

- **Permissions:** `manage_commission_clawback_disputes`, `correct_commission_clawbacks` (admin by seeder; not implied by view/process/waive; not via `adjust_wallets` / refunds / settlements)
- **Decision model (extended):** same `commission_clawback_decisions` + `CLD-*`
  - types: `dispute_opened` (status `open`), `dispute_resolved` (status `recorded`), `correction` (status `posted`)
  - columns: `parent_decision_id`, `safe_resolution_summary`
  - `reason_code` stored as string (type-specific allowlists)
- **Active dispute:** derived — open row without child `dispute_resolved` (no stored clawback `disputed` status)
- **Pause:** Process / Retry / sweeper no-op or skip while active dispute; late jobs harmless
- **Open:** `OpenCommissionClawbackDispute` — no WTX
- **Resolve:** `ResolveCommissionClawbackDispute` — rejected/withdrawn (no money; may redispatch unposted pending); accepted_as_waiver → `WaiveCommissionClawback`; accepted_as_correction → `CorrectCommissionClawback` (requires matching financial permission)
- **Correction TX:** `commission_reversal_correction` credit via `WalletLedger::postCredit`; key `commission_reversal_correction:{decision_id}`
- **Arithmetic (shared):** `remaining = posted_reversal − waiver_credits − correction_credits`
- **Eligibility:** `CommissionClawbackDisputeEligibility`, `CommissionClawbackCorrectionEligibility` (direct correction allowed with correct permission)
- **Presentation:** disputed / partially|fully corrected derived; never call a corrected clawback “waived”
- **Surfaces:** admin detail dispute/correction forms; inbox filters/badges; Earnings corrected_back + under-review; ledger/detail/receipt distinct from waiver
- **Notify/realtime:** dispute opened/resolved + correction posted; Activity informational; `CommissionStateChanged` (+ `TransactionPosted` for corrections); AdminOps `clawback-dispute-*` / `clawback-correction-posted`
- **Audit:** `commission.clawback.dispute_opened|dispute_resolved|correction_posted`
- **Tests:** `CommissionClawbackDisputeAndCorrectionTest` + M7.1–M7.2.2 green

### Lock order

1. `CommissionClawback`
2. Active dispute + decision rows
3. Reversal WTX (correction path)
4. Correction/waiver decision
5. Salesperson wallet
6. WalletLedger

### Gotchas

- One active dispute per clawback (enforced under lock)
- Dispute resolve must not duplicate WTX on resolution row (`related_wallet_transaction_id` unique — financial decision owns the WTX)
- Accept-as-waiver/correction requires both dispute permission **and** waive/correct permission
- No SP self-service dispute route
- No threaded messages / attachments / ticketing

### Deferred

- *(none for Track B milestones)* — see **M7.2.4** + Track B closure below

---

## M7.2.4 — Historical Commission Exposure + Track B Closure (shipped)

### Goal

Read-only historical exposure report for credited commissions with posted refunds outside automatic M7.1 clawback, plus non-financial review markers — then close Track B. No historical collection.

### Shipped — 2026-08-01

- **Permission:** `view_historical_commission_exposure` (admin by seeder; view + review; not via adjust_wallets / process / settlements)
- **Route:** `GET /admin/commission-clawbacks/historical-exposure` (`admin.commission-clawbacks.historical-exposure`) — registered **before** `{clawback}` binding
- **Nav:** tab/link from clawback inbox (permission-gated); no new top-level nav; no sidebar historical badge count
- **Read Action:** `GetHistoricalCommissionExposure` → `HistoricalCommissionExposureItemDTO` → `AdminHistoricalCommissionExposurePresenter`
- **Classifier:** `HistoricalCommissionExposureClassifier` — confirmed vs incomplete; outside-policy via `effective_at`
- **Query grain:** credited Commission ↔ fulfillment ↔ posted refund (Fulfillment or OrderItem reference) ↔ optional credit WTX; exclude clawbacks + posted `commission_reversal` idempotency keys
- **Default window:** 24 months refund lookback; pagination 20; confirmed unreviewed first, newest refund, ID tie-break
- **Filters:** unreviewed / reviewed / confirmed / incomplete / all; search commission id, order #, credit/refund WTX; salesperson_id supported on Action
- **Review model:** `historical_commission_exposure_reviews` + `HistoricalCommissionExposureReview`
- **Review Action:** `ReviewHistoricalCommissionExposure` — revalidate pair, upsert marker, activity_log; idempotent same-outcome replay; **never** WalletLedger / clawback / Commission / refund mutation
- **Outcomes:** `platform_absorbed`, `not_actionable`, `insufficient_data`, `duplicate_or_invalid`, `deferred_review`
- **UI:** Livewire `HistoricalCommissionExposureIndex` + warning “no financial action will occur”; EN/AR strings; money LTR; status not color-only
- **Performance:** page-local summary only; EXPLAIN uses existing indexes + review unique; no new materialization; no global historical count on every request
- **CSV export:** **deferred** (no safe existing admin export pattern adopted)
- **Tests:** `HistoricalCommissionExposureTest` + M7.1–M7.2.3 regression green

### Exposure definition (strict)

| Class | Rule |
|---|---|
| Confirmed | Credited + valid original credit + proven refund↔fulfillment + no clawback + no posted reversal + outside policy |
| Incomplete | Candidate join exists but credit link missing/invalid |
| Not in report | Pending/failed commission; no proven refund; posted reversal; any clawback row (post-policy inbox); inside `effective_at` window |

### Gotchas

- Review marker ≠ money movement and ≠ CommissionClawback
- Incomplete rows must not be presented as confirmed financial loss
- Post-policy `needs_review` stays in clawback inbox — not this report
- Meta-only `fulfillment_id` refunds without morph match are **not** joined (incomplete source → excluded rather than inventing exposure)
- Waiver/correction without reversal is inconsistent → classify incomplete at pair revalidation if ever surfaced

### Track B closure review (2026-08-01)

| Slice | Status |
|---|---|
| M7.0 policy | Shipped (prospective clawback; customer refund independent) |
| M7.1 reversal kernel | Shipped |
| M7.2.1 admin retry ops | Shipped |
| M7.2.2 waivers | Shipped |
| M7.2.3 disputes/corrections | Shipped |
| M7.2.4 historical exposure | Shipped (report + review only) |

**Durable truths confirmed:** customer refund independent; original credits/reversals immutable; all money through WalletLedger; typed debit/credit corrections; admin actions permissioned; retries do not alter financial facts; historical reporting does not collect money; SP debt reconciles via wallet arithmetic; Earnings/wallet surfaces reconcile; no generic wallet adjustment for clawbacks.

**Remaining deferred (real, not new Track B milestones):** MySQL concurrency stress tests; browser Arabic/Reverb/manual acceptance; full-suite memory grouping; decimal-width hardening; unified Financial Exceptions Centre; salesperson self-service dispute; **historical manual collection (intentionally rejected/deferred)**; CSV export.

**Track B verdict:** **Closed.** Recommended next: **Track C automation** (ops cost) or **Track D growth** (conversion) — Omar chooses; do not invent M7.2.5 for deferred items.

### Deferred after Track B

- Historical manual collection (rejected as product default)
- CSV export of historical exposure
- Unified Financial Exceptions Centre
- SP self-service dispute

---

## M7.1 — Commission Reversal Kernel (shipped)

### Goal

Durable clawback obligation + immutable `commission_reversal` debit after late customer refund of a credited commission, without blocking the customer refund.

### Shipped

- **Date:** 2026-07-31
- **Obligation model:** `commission_clawbacks` + `CommissionClawback` (`CLB-*` public_ref)
- **Statuses:** `pending` | `processing` | `posted` | `needs_review` | `failed` (no disputed/waived in M7.1)
- **Ledger:** `WalletTransactionType::CommissionReversal` debit via `WalletLedger::postCommissionReversal` (`allowClawbackDebt` narrowly guarded)
- **Idempotency:** obligation unique `(commission_id, refund_wtx)` + key `commission_clawback:{c}:refund:{r}`; reversal key `commission_reversal:{c}:refund:{r}`
- **Refund integration:** `ApproveRefundRequest` creates obligations inside refund TX; `ProcessCommissionClawbackJob` after commit
- **Debt:** negative salesperson balance only via authorised reversal; purchases floor at 0; payout requests return `clawback_debt`
- **Surfaces:** Earnings gross/reversed/net/debt; overview clawback debt label; ledger/detail/receipt mapping; realtime `TransactionPosted` + `CommissionStateChanged`
- **Policy:** `config('billing.commission_clawback')` version + optional `effective_at` (prospective only)
- **Tests:** `tests/Feature/CommissionClawbackTest.php` (+ related earnings/ledger/refund suites green)

### Key files

- `app/Models/CommissionClawback.php`, migrations
- `app/Actions/Commissions/CreateCommissionClawbackObligations.php`
- `app/Actions/Commissions/ProcessCommissionClawback.php`
- `app/Jobs/ProcessCommissionClawbackJob.php`
- `app/Services/WalletLedger.php` (`postCommissionReversal`)
- `app/Actions/Refunds/ApproveRefundRequest.php`
- `app/Actions/Earnings/GetSalespersonEarnings.php`
- `app/Support/Commissions/SalespersonClawbackDebt.php`

### Lock order

- Refund TX: refund WTX → fulfillment/item/order → customer wallet → commissions (pending fail + obligation create)
- Clawback processing: clawback → commission → original credit WTX → salesperson wallet (WalletLedger)

### Gotchas

- Customer refund never depends on clawback job success
- Original `commission_credit` immutable; reverse with new debit only
- `allowClawbackDebt` rejected unless type is `commission_reversal`
- Admin waiver/dispute/queue = M7.2 only
- No automatic historical backfill
- Unsigned MySQL FK names shortened (`cc_*`) for identifier length

### Deferred M7.2

- See **M7.2.0 architecture** above for inbox/retry/waiver/dispute/correction/historical split (M7.2.1–M7.2.4)
- Formal salesperson clawback detail route (CLB-* reserved; not required for M7.2.1)

---

## M7.0 — Commission Clawback Policy and Architecture

### Executive verdict

Late refunds after commission credit are a **documented Phase-1 financial gap**: customer refund still posts; credited commission stays; salesperson may already have spent the funds.

**Approved policy:**

1. Keep pending → `failed` on refund approve (unchanged).
2. After credit, refund of a fulfillment triggers **full clawback of that fulfillment’s commission** (grain-aligned; not order-wide proportional).
3. Customer refund **must never fail** because of salesperson clawback.
4. Use a durable **clawback obligation** workflow + immutable `commission_reversal` WalletTransaction via `WalletLedger`.
5. Apply **prospectively** only; report historical exposure without auto-debit.
6. Permit a controlled negative salesperson wallet balance only for authorised commission reversals.

### Approved M7.1 policy contract

- Credited commissions are reversible at full commission grain per refunded fulfillment.
- No finality window in v1.
- Every posted refund for the related fulfillment triggers automatic processing; anomalies become `needs_review`.
- Customer refund completion is independent: clawback failure cannot block, reduce, or roll back it.
- `commission_reversal` is the only authorised transaction allowed to push a salesperson wallet below zero.
- Customer credit-facility architecture is not reused for salesperson clawback debt.
- Purchases are allowed only when `availableToSpend()` is positive and may never push the salesperson wallet further below zero.
- Future wallet credits, including full payout-batch commission credits, repay negative balance through ordinary wallet arithmetic.
- Payout requests are blocked while clawback debt exists.
- Policy is prospective-only; historical refunds are not automatically clawed back.
- Original `commission_credit` remains immutable.
- Waiver, dispute, and admin exception interfaces are deferred to M7.2.

### Proven current facts (code)

| Fact | Evidence |
|---|---|
| Commission grain | **Per fulfillment** (`fulfillment_id` unique) |
| Create | `PayOrderWithWallet` afterCommit; `amount = unit_total × rate/100` (`line_total/qty`; custom = full `line_total`) |
| Wallet money | Only `CreatePayoutBatch` → `commission_credit:{id}` |
| Pending on refund | → `failed` by `fulfillment_id` |
| Credited on late refund | **Not reversed** (explicit) |
| Refund grain | **One failed fulfillment**; amount frozen at request; approve promotes (no admin arbitrary amount) |
| Salesperson wallet | Same customer wallet type; credit facility floor is **not** a salesperson clawback facility |
| Test gap | Pending→failed proven; **late credited intact not covered by a dedicated test** |

### Terminology (EN / AR gist)

| Term | EN | AR gist | Money? |
|---|---|---|---|
| Provisional commission | Pending / eligible, may fail | عمولة مبدئية | No |
| Credited commission | Posted `commission_credit` | عمولة مُضافة للمحفظة | Yes |
| Commission reversal | Immutable debit `commission_reversal` | عكس عمولة | Yes (out) |
| Clawback obligation | Workflow intent to reverse | التزام استرداد عمولة | Obligation only |
| Outstanding clawback debt | Unrecovered after/with reversal policy | مديونية استرداد عمولة | Policy-dependent |
| Waiver | Approved non-collection | إعفاء | Accounting decision |
| Dispute | Contested recovery | اعتراض | Pauses ops |
| Final commission | **Not defined in v1** unless Omar adds a window | — | — |

Avoid: “refund commission”, “paid” for wallet credit, treating `failed` as previously credited.

### Policy models compared

| Model | Idea | Pros | Cons |
|---|---|---|---|
| **A** No clawback after credit | Platform absorbs | Simple, salesperson-friendly | Unbounded platform loss |
| **B\* Recommended** Per-fulfillment immediate clawback | Reverse that commission on late refund | Matches grain; clear accounting | Debt/negative-balance complexity |
| **C** Protected window | Reversible for N days after credit | Fairer timing | Window config + edge cases |
| **D** Delay credit / escrow | Credit only after risk window | No post-credit debt | Delays salesperson cash; product change |

**Reject A** as long-term policy. **Prefer B\*** for M7.1 unless Omar chooses C (window) or D (product shift).

### Recommended calculation (grain-aligned)

Because commission and refund share **fulfillment** grain:

- Refund of fulfillment F after commission F is credited →  
  `target_reversal = commission_amount`  
  `new_reversal = target − already_reversed` (LedgerMoney scale 2)  
  never > remaining credited net
- Multiple fulfillments on one line: reverse only the refunded unit’s commission
- Custom-amount (1 FF): full refund ↔ full commission clawback
- Free-form partial USD refund of one FF **does not exist today** — if added later, switch to cumulative basis ratio using stored `order_total` snapshot as basis
- Prefer cumulative target math if proportional paths appear later

### Ledger recommendation

- New type: `commission_reversal` (debit, absolute amount + direction)
- Through `WalletLedger` only
- Do **not** mutate/delete original `commission_credit`
- Idempotency: `commission_reversal:{commission_id}:refund:{refund_tx_id}` (+ unique constraint on clawback row)
- Receipt/meta: original credit WTX, commission id, refund WTX, policy version — no customer secrets

### Workflow model recommendation

**Yes — dedicated `commission_clawbacks` (or equivalent) obligation table.**

Why: customer refund must commit even if salesperson wallet/posting fails; durable retry; admin queue; separation of obligation vs posted money.

Statuses (candidate): `pending` | `posted` | `disputed` | `waived` | `failed` | `needs_review`

Commission status stays `pending|credited|failed`. Clawback state is **separate** (do not overload `failed` for credited rows). Derive reversed totals from posted reversals where possible.

### Salesperson debt (approved)

Current code: salesperson wallet floor is `0.00` unless a **customer credit facility** is Active (must not auto-reuse for clawback).

Debt mode B is approved: post the full reversal and permit controlled negative balance. This is a clawback-specific ledger exception, not a credit facility. Future credits repay debt normally; payout requests remain blocked while negative.

### Atomicity recommendation

1. Approve refund: post customer refund + fail pending commissions + **create clawback obligation** for credited commissions (same TX when possible).
2. Post `commission_reversal` synchronously if safe; else after-commit idempotent job.
3. Customer refund never rolls back on clawback failure.
4. Broadcast/notify after commit only; failures isolated.

Lock order (candidate): order → order item → fulfillment → refund TX → commission → clawback row → salesperson wallet → (customer wallet already locked earlier in refund path — document final order in M7.1 to avoid deadlock with payout batch).

### Historical applicability

**Prospective-only** after policy effective date / `policy_version = 1`.  
Admin report for historical late-refund exposure; **no automatic historical debit**.

### Surfaces (future M7.1+, not built now)

- Earnings: gross credited, reversed, net; debt; links to credit + reversal WTX
- Ledger/detail: money-out commission reversal; typed destination to Earnings
- Overview: if negative allowed, distinguish clawback debt vs credit-facility debt
- Payout request: block while debt > 0
- Realtime: `TransactionPosted` + `CommissionStateChanged` (no new reason required for v1)
- Admin (M7.2): queue pending/failed/disputed/waiver; explicit audited Actions only

### Explicit non-goals (M7.0)

- No production code / migrations
- No clawback posting
- No admin correction tools
- M7.1 implementation was not part of M7.0
- No Git/deploy

### Findings (material)

| ID | Severity | Finding | Blocks M7.1? |
|---|---|---|---|
| F01 | High | Late credited commissions never reversed | Policy first |
| F02 | Medium | No dedicated test that credited survives late refund | Add in M7.1 |
| F03 | Medium | Salesperson floor ≠ clawback debt facility | Decision required |
| F04 | Low | `Order::commission()` hasOne is stale vs per-FF | Cleanup later |
| F05 | Informational | Refund amount frozen at request; no free-form partial USD | Design assumes FF grain |

### Suggested M7 sequence (after approvals)

- **M7.1** — Clawback obligation + `commission_reversal` posting kernel (automated path)
- **M7.2** — Admin clawback ops (retry/waive/dispute)
- **M7.3** — Earnings/ledger UX + notifications polish
- Then reopen Track C/D per [[Future Roadmap - Automation and Growth]]
