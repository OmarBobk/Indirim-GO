---
status: backlog
created: 2026-07-30
owner: Omar
type: roadmap
---

# Future Roadmap — Automation and Growth

Deferred tracks after **Track B — Financial Risk and Admin Operations** closed (M7.2.4). Choose Track C or D from real ops/conversion pressure.

Related: [[Fulfillments & Automation]], [[Orders & Checkout]], [[Refunds & Settlements]], [[Customer Financial Centre]], [[Wallet & Ledger]], [[M7 — Financial Risk and Admin Operations]]

## Track map

| Track | Focus | Status |
|---|---|---|
| **B** | Financial risk + admin ops | **Closed** (M7.0–M7.2.4) |
| **C** | Fulfilment / supplier automation | Backlog — recommended next if ops cost dominates |
| **D** | Growth / conversion | Backlog — recommended next if conversion dominates |

Suggested order inside each track below. Adjust from real ops bottlenecks and conversion data.

---

## Current decision (post Track B)

**Closed:** Track B — Financial Risk and Admin Operations (through M7.2.4 historical exposure report-only)

**Recommended next:** Track C (automation) **or** Track D (growth) — Omar chooses.

- Historical manual collection remains intentionally deferred/rejected.
- Do not invent M7.2.5 for deferred stress/UI/export items.

---

## Track C — Fulfilment and Supplier Automation

Suggested order: **C1 → C2 → C3 → C4**

### C1 — Wasim production hardening

**Goal:** Safer, observable Wasim purchase/reconcile automation in production.

**Scope candidates**
- Failure taxonomy, retry/backoff, stale-run handling
- Session-expiry monitoring; credential/browser alerts
- Worker health + build-version visibility
- Screenshot/artifact investigation UX
- Rate-limit protection; metrics/failure budgets
- Operator runbooks; kill-switch / rollout verification

**Start when:** live Wasim volume is regular and manual/session failures are a real ops cost.

### C2 — Second supplier driver

**Goal:** Another supplier on the existing browser-worker + Laravel orchestration model.

**Scope candidates**
- Feasibility audit; driver interface/mapping contract
- Credentials/session; purchase + reconcile flows
- Callback mapping; package/product mapping; price scans
- Feature flags; staged rollout/rollback
- HMAC + idempotency verification

**Start when:** a second stable supplier exists and concentration/coverage justifies cost.

### C3 — Intelligent supplier routing

**Goal:** Pick best eligible supplier by price, availability, margin, and health.

**Scope candidates**
- Eligibility, live/scanned price compare, margin floors
- Preferred + fallback suppliers; health weighting
- Manual override; decision snapshots + audit explainability
- Safe no-route → manual fulfilment

**Dependency:** ≥2 reliable drivers (after C2).

### C4 — Automated price update workflow

**Goal:** Controlled review path from supplier scans → entry-price updates.

**Scope candidates**
- Scheduled scans; drift thresholds; suggested updates
- Margin simulation; high-risk alerts; batch review
- Approval permissions; change snapshots; rollback/audit
- Rules for custom-amount products

Builds on existing price-drift work in [[Fulfillments & Automation]].

---

## Track D — Growth and Customer Conversion

Suggested order: **D1 → D4 → D2 → D3**

### D1 — Buy again and reorder

**Goal:** Lower friction for repeat digital-product purchases.

**Scope candidates**
- Buy again / recent / favourites / reorder templates
- Repeat custom amounts; safe saved player identifiers
- Server-authoritative repricing + change warnings
- Fast wallet checkout; failed-payment recovery

**Safety:** no unnecessary secrets; ownership + deletion for saved IDs; server owns price.

### D2 — Referral growth tools

**Goal:** Measurable, shareable referral acquisition with abuse controls.

**Scope candidates**
- Landing UX; campaign links; QR; attribution
- Salesperson/customer analytics + funnel quality
- Anti-self-referral; velocity/abuse checks; reward experiments

### D3 — Promotions and campaign engine

**Goal:** Controlled promotions without weakening authoritative pricing.

**Scope candidates**
- Coupons, package/segment/first-order offers
- Dates, usage/user limits, budget caps, margin floors
- Priority/conflict rules; decision snapshots; admin audit
- Fixed + custom-amount compatibility

**Safety:** server-authoritative recalculation; store promotion snapshots on order; never trust client totals.

### D4 — Customer retention

**Goal:** Bring customers back at useful moments without spam.

**Scope candidates**
- Reorder / abandoned-checkout / top-up reminders
- Loyalty prompts; inactive campaigns; availability signals
- Push/email/WhatsApp strategy; frequency caps; opt-out
- Attribution + retention reporting

---

## Open questions

- Pause Track B for C1 if Wasim ops cost spikes?
- Prefer D1 conversion lift vs D2 salesperson growth first after B?
- Any Track C work blocked on Mobile M2 commerce shell? (assume no unless routing needs mobile)
