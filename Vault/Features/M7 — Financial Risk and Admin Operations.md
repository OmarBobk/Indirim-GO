---
status: architecture
created: 2026-07-30
feature: m7-financial-risk-admin-ops
milestone: M7.0
owner: Omar
type: policy-architecture
---

# M7 — Financial Risk and Admin Operations

Track B. Canonical home for commission clawback policy and later admin financial-risk tooling.

**M7.0 = architecture + policy only.** No code, migrations, or clawback implementation.

Related: [[Refunds & Settlements]], [[Wallet & Ledger]], [[Customer Financial Centre]], [[Orders & Checkout]], [[Future Roadmap - Automation and Growth]]

---

## M7.0 — Commission Clawback Policy and Architecture

### Executive verdict

Late refunds after commission credit are a **documented Phase-1 financial gap**: customer refund still posts; credited commission stays; salesperson may already have spent the funds.

**Recommended policy (pending Omar approval):**

1. Keep pending → `failed` on refund approve (unchanged).
2. After credit, refund of a fulfillment triggers **full clawback of that fulfillment’s commission** (grain-aligned; not order-wide proportional).
3. Customer refund **must never fail** because of salesperson clawback.
4. Use a durable **clawback obligation** workflow + immutable `commission_reversal` WalletTransaction via `WalletLedger`.
5. Apply **prospectively** only; report historical exposure without auto-debit.
6. **Omar must decide** negative salesperson balance vs partial-post + external debt, purchase restrictions, waiver/dispute, and any finality window.

**Do not start M7.1 until Omar answers § Required Omar decisions.**

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

### Salesperson debt (requires Omar)

Current code: salesperson wallet floor is `0.00` unless a **customer credit facility** is Active (must not auto-reuse for clawback).

Options for insufficient balance:

| Option | Meaning |
|---|---|
| B | Allow controlled negative balance for clawback only |
| C | Post max available + record remaining external debt |
| E | Admin review gate above threshold |

**Architecture lean:** prefer **B or C** with payout requests blocked while debt > 0; future credits reduce debt by ordinary balance arithmetic; **no** customer credit-facility reuse. Purchases while in debt = Omar decision (availableToSpend already blocks if balance ≤ 0 under option B).

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

### Required Omar decisions (before M7.1)

1. Reversible after credit? **Rec: Yes (B\*)**
2. Full per-fulfillment vs proportional? **Rec: Full per-fulfillment**
3. Finality window? **Rec: None in v1** (optional C later)
4. Negative salesperson balance? **Must choose B vs C**
5. Purchase while debt? **Rec: only if availableToSpend > 0**
6. Future credits repay debt? **Rec: Yes (ordinary arithmetic)**
7. Admin waive/reduce? **Rec: Yes in M7.2, audited**
8. Salesperson dispute? **Rec: Optional M7.2**
9. Prospective only? **Rec: Yes**
10. Auto vs review threshold? **Rec: Auto for standard; review if anomaly/missing credit TX**
11. Which refund reasons? **Rec: All posted customer refunds of the fulfillment**
12. Block payout requests in debt? **Rec: Yes**

### Explicit non-goals (M7.0)

- No production code / migrations
- No clawback posting
- No admin correction tools
- No M7.1 start
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
