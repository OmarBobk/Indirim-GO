---
status: active
created: 2026-07-30
updated: 2026-08-01
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
| **C** | Fulfilment / supplier automation | **Active** — C1.3 code shipped; **C1.4 not closed** (2026-08-06) |
| **D** | Growth / conversion | Backlog — recommended next if conversion dominates |

Suggested order inside each track below. Adjust from real ops bottlenecks and conversion data.

---

## Current decision (post Track B)

**Closed:** Track B — Financial Risk and Admin Operations (through M7.2.4 historical exposure report-only)

**Active:** Track C — C1 Automation Reliability (see [[C1 — Automation Reliability and Supplier UI Resilience]]).

**Also available:** Track D (growth) if conversion dominates — Omar chooses priority between C1 delivery and D1.

- Historical manual collection remains intentionally deferred/rejected.
- Do not invent M7.2.5 for deferred stress/UI/export items.

---

## Track C — Fulfilment and Supplier Automation

Suggested order: **C1 → C2 → C3 → C4**

### C1 — Automation reliability and supplier UI resilience

**Canonical note:** [[C1 — Automation Reliability and Supplier UI Resilience]]

**Goal:** Live ops dashboard + structured progress; Wasim UI adapters/contracts; circuit breakers; production hardening.

| Slice | Focus | Status |
|---|---|---|
| **C1.0** | Architecture + audit only | **Done** 2026-08-01 (no code) |
| **C1.1** | Live Automation Operations Dashboard + progress/heartbeat | **Shipped** 2026-08-05 |
| **C1.2** | Wasim UI adapters + page contracts + health probe | **Shipped** 2026-08-05 |
| **C1.3** | Circuit breakers / auto pause / probe-gated resume | **Shipped (code)** 2026-08-05 |
| **C1.4** | Production acceptance + hardening | **Not closed** 2026-08-06 — gates green locally; live probe/purchase not performed |

**Do not** invent a second Wasim UI adapter without live fixtures. Do not start C2 until C1 is closed (A) or safely paused (B) with an explicit business decision.

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
