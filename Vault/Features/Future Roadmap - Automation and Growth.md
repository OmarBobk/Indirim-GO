---
status: backlog
created: 2026-07-30
owner: Omar
type: roadmap
---

# Future Roadmap — Automation and Growth

Deferred tracks while **Track B — Financial Risk and Admin Operations** is active. Do not start C or D until Track B is closed or deliberately paused.

Related: [[Fulfillments & Automation]], [[Orders & Checkout]], [[Refunds & Settlements]], [[Customer Financial Centre]], [[Wallet & Ledger]]

## Track map

| Track | Focus | Status |
|---|---|---|
| **B** | Financial risk + admin ops | **Active** (M7.1 + M7.2.1 shipped; M7.2.2 waiver next) |
| **C** | Fulfilment / supplier automation | Backlog |
| **D** | Growth / conversion | Backlog |

Suggested order inside each track below. Adjust from real ops bottlenecks and conversion data.

---

## Current decision (Track B)

**Active:** Track B — Financial Risk and Admin Operations

**Next milestone:** [[M7 — Financial Risk and Admin Operations]] — **M7.2.2 Waiver** (after M7.2.1 ops surface)

- M7.0 policy approved 2026-07-30; M7.1 kernel shipped 2026-07-31; M7.2.1 inbox/retry shipped 2026-08-01.
- Do not start waiver/dispute/correction/historical until Omar opens that milestone.

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
